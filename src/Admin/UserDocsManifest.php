<?php

namespace SilverStripe\UserDocs\Admin;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Manifest\ManifestFileFinder;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use Locale;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\ArrayLib;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Path;
use SilverStripe\i18n\i18n;
use Symfony\Component\Filesystem\Path as FilesystemPath;

/**
 * A utility class which builds a manifest of where to find all user documentation and caches it.
 *
 * It finds the following information:
 *   - The canonical location of the file for each documentation slug.
 *     If more than one module has a doc with the same slug, the module with the highest priority is used.
 *   - Metadata for each doc, such as its title, sort order and frontmatter.
 *   - Which pages have which localisations.
 *   - The tree structure used to display links to the documentation.
 */
class UserDocsManifest implements Flushable
{
    use Injectable;

    protected const CACHE_KEY = 'manifest';

    /**
     * Array of properties to cache
     */
    protected array $cachedProperties = [
        'docData',
        'treeData',
    ];

    private ?CacheInterface $cache = null;

    /**
     * The full set of doc paths.
     * First key is the locale (e.g. "en")
     * Second key is the URL slug (e.g. "some-feature/sub-page")
     * Value is an array with metadata about the file including the full path
     */
    private array $docData = [];

    /**
     * The minimal information required to build a tree of links for documentation.
     * The data is hierarchical, with each node's children in a 'children' key.
     */
    private array $treeData = [];

    public static function flush(): void
    {
        Injector::inst()->get(CacheInterface::class . '.userdocsmanifest')->clear();
    }

    /**
     * Get the request locale and fallbacks in order of precedence.
     *
     * @return string[]
     */
    public static function getLocalePriority(): array
    {
        $userLocale = i18n::get_locale();
        $fallbackUserLocale = Locale::getPrimaryLanguage($userLocale);
        $defaultLocale = i18n::config()->uninherited('default_locale');
        $fallbackDefaultLocale = Locale::getPrimaryLanguage($defaultLocale);
        // We make it unique to avoid duplicate data in the merge.
        return array_unique([
            $userLocale,
            $fallbackUserLocale,
            $defaultLocale,
            $fallbackDefaultLocale,
            'en',
        ]);
    }

    /**
     * Initialise the documentation manifest
     *
     * @param string[] $validPaths Absolute paths to directories that contain user documentation
     */
    public function init(array $validPaths, bool $includeTests = false): void
    {
        $this->cache = $this->buildCache();
        // If cache is safe to use, use it
        if (
            $this->cache
            && ($data = $this->cache->get(static::CACHE_KEY))
            && $this->loadState($data)
        ) {
            return;
        }
        // Otherwise build from scratch
        $this->regenerate($validPaths, $includeTests);
    }

    /**
     * Completely regenerates the manifest file.
     *
     * @param string[] $validPaths Absolute paths to directories that contain user documentation
     */
    public function regenerate(array $validPaths, bool $includeTests): void
    {
        // Reset the manifest so stale info doesn't cause errors.
        $this->loadState([]);
        // Get metadata about all user documentation
        $docData = [];
        $finder = new ManifestFileFinder();
        $finder->setOptions([
            'name_regex' => '/.*\\.md$/',
            'ignore_tests' => !$includeTests,
            'file_callback' => function ($fileName, $filePath) use ($validPaths, &$docData) {
                $basePath = $this->findBasePath($filePath, $validPaths);
                if ($basePath) {
                    $this->handleFile($docData, $fileName, $filePath, $basePath);
                }
            },
        ]);
        $finder->find(BASE_PATH);
        $docData = $this->mergeModuleData($docData);
        $docData = $this->sortDocData($docData);
        $treeData = $this->collateTreeData($docData);
        $data = [
            'docData' => $docData,
            'treeData' => $treeData,
        ];
        $this->loadState($data);
        if ($this->cache) {
            $this->cache->set(static::CACHE_KEY, $data);
        }
    }

    /**
     * Merge data from modules, respecting module priority order.
     *
     * The result is that all original documentation is retained for all modules, but any
     * collision where two modules contain docs with the same slug will be resolved by the
     * module with the higher priority retaining its documentation and the lower priority
     * doc being discarded.
     */
    private function mergeModuleData(array $data): array
    {
        // Sort data by module priority order as determined by the module manifest.
        $sortedModules = array_map(
            fn (Module $module) => $module->getName(),
            ModuleLoader::inst()->getManifest()->getModules()
        );
        uksort($data, function (string $moduleA, string $moduleB) use ($sortedModules) {
            $posA = array_search($moduleA, $sortedModules);
            $posB = array_search($moduleB, $sortedModules);

            // If a key isn't found in the order array, push it to the beginning as it is now
            // the least important module.
            // That *shouldn't* ever happen but it doesn't hurt to account for the edge case.
            if ($posA === false) {
                return -1;
            }
            if ($posB === false) {
                return 1;
            }

            // Note that the sorted modules are in priority order where the FIRST module is
            // the most important. We want the reverse order here so that the most important
            // module gets to override everything in the next step.
            return $posB <=> $posA;
        });

        // Merge in and replace data as appropriate.
        // Note we can't just use the built-in array_replace_recursive() because
        // that will merge metadata about documentation for two conflicting associative arrays
        // for doc data rather than outright replacing one with the other.
        $result = [];
        foreach ($data as $datum) {
            foreach ($datum as $locale => $localeData) {
                foreach ($localeData as $slug => $docData) {
                    if (!isset($result[$locale][$slug]) || !$this->isRootWithoutIndex($docData)) {
                        $result[$locale][$slug] = $docData;
                    }
                }
            }
        }
        return $result;
    }

    private function sortDocData(array $data): array
    {
        foreach ($data as $locale => &$localeData) {
            uasort($localeData, function (array $a, array $b) {
                // If both have order, compare by order first (regardless of directory)
                // This ensures siblings with order are sorted together
                if ($a['order'] !== null && $b['order'] !== null) {
                    // If orders are different, sort by order
                    if ($a['order'] !== $b['order']) {
                        return $a['order'] <=> $b['order'];
                    }
                    // Both are folders or both are files, fall through to directory/title comparison
                }

                // If one document is an index file, it comes first
                // Orders are the same - folders come before files
                // isIndex = true means it's a folder (index.md)
                // isIndex = false means it's a regular file
                if ($a['isIndex'] !== $b['isIndex']) {
                    return $a['isIndex'] ? -1 : 1;
                }

                // If files are in different directories use absolute paths to determine order
                $dirA = dirname($a['filePath']);
                $dirB = dirname($b['filePath']);
                if ($dirA !== $dirB) {
                    return $dirA <=> $dirB;
                }

                // If only one has order, it comes first
                if ($a['order'] !== null) {
                    return -1;
                }
                if ($b['order'] !== null) {
                    return 1;
                }

                // Compare alphabetically against file titles
                return $a['title'] <=> $b['title'];
            });
        }
        return $data;
    }

    private function findBasePath(string $filePath, array $validPaths): string|false
    {
        foreach ($validPaths as $candidate) {
            if (FilesystemPath::isBasePath($candidate, $filePath)) {
                return $candidate;
            }
        }
        return false;
    }

    /**
     * Visit a file to inspect for user help documentation.
     * Updates the $data array in place, so that further transformations (e.g. sorting) can be done
     * before storing the data as manifest state.
     *
     * @param array $data The array the file's metadata will be added to
     * @param string $basePath The path to the registered documentation folder this file comes from
     */
    public function handleFile(array &$data, string $fileName, string $filePath, string $basePath): void
    {
        if (!is_dir($filePath)) {
            // ONLY parse the frontmatter at this stage
            $frontMatterExtension = new FrontMatterExtension();
            $result = $frontMatterExtension->getFrontMatterParser()->parse(file_get_contents($filePath));
            $frontMatter = $result->getFrontMatter();
            $hasContent = !empty(trim($result->getContent()));
        } else {
            $frontMatter = [];
            $hasContent = false;
        }
        $isIndex = $fileName === 'index.md';
        $moduleObj = ModuleLoader::inst()->getManifest()->getModuleByPath($filePath);
        $module = $moduleObj->getName();

        $pathFromBase = ltrim(FilesystemPath::makeRelative($filePath, $basePath), '/');
        $locale = explode('/', $pathFromBase)[0];
        $pathFromLocale = ltrim(FilesystemPath::makeRelative($pathFromBase, $locale), '/');
        $isRootDir = is_dir($filePath) && !str_contains($pathFromLocale, '/');
        if (!$isRootDir) {
            // Validate there's a root
            $dir = dirname($pathFromLocale);
            if ($dir === '.' || $dir === '/' || $dir === '\\') {
                throw new InvalidArgumentException(
                    'User documentation files must have at least one directory between the locale and file.'
                );
            }
        }
        $slug = $this->getSlugForPath($pathFromLocale, $isRootDir);
        $parentSlug = dirname($slug);
        if ($parentSlug === '.') {
            $parentSlug = null;
        }

        if (!array_key_exists($locale, $data[$module] ?? [])) {
            $data[$module][$locale] = [];
        }

        // Make sure root node data is registered if it has no doc file.
        // Note we don't register branch nodes in the same way because those should always have associated docs.
        if (
            $parentSlug
            && !array_key_exists($parentSlug, $data[$module][$locale])
            && !str_contains($parentSlug, '/')
        ) {
            $basePathWithLocale = Path::join($basePath, $locale);
            $rootDirName = explode('/', $pathFromLocale)[0];
            $this->handleFile($data, $rootDirName, Path::join($basePathWithLocale, $rootDirName), $basePath);
        }

        $data[$module][$locale][$slug] = [
            'order' => $this->extractOrderFromFilename($isIndex ? basename(dirname($filePath)) : $fileName),
            'frontMatter' => $frontMatter,
            'title' => $frontMatter['title'] ?? $this->filenameToTitle($fileName, $filePath),
            'isIndex' => $isIndex,
            'hasContent' => $hasContent,
            'fileName' => $fileName,
            'filePath' => $filePath,
            'module' => $module,
            'locale' => $locale,
            'slug' => $slug,
            'parentSlug' => $parentSlug,
            // Used to find resources such as images, which are relative to the file
            'rootPathInModule' => ltrim(FilesystemPath::makeRelative($basePath, $moduleObj->getPath()), '/'),
            'pathFromLocale' => $pathFromLocale,
        ];
    }

    /**
     * Load current state into an array of data.
     */
    public function getState(?string $locale = null): array
    {
        return $this->docData;
    }

    /**
     * Get all data about documentation for the current locale.
     * Falls back to the shortened locale and then to the default locale.
     * For example pt_BR will fallback to pt which will then fallback to en_US which falls back to en.
     */
    public function getLocalisedDocData(): array
    {
        $result = [];
        foreach ($this->getDataForLocales($this->docData) as $localeData) {
            foreach ($localeData as $slug => $docDatum) {
                if (!isset($result[$slug]) || !$this->isRootWithoutIndex($docDatum)) {
                    $result[$slug] = $docDatum;
                }
            }
        }
        return $result;
    }

    /**
     * Get the hierarchical-structured tree data for the current locale.
     * Falls back to the shortened locale and then to the default locale.
     * For example pt_BR will fallback to pt which will then fallback to en_US which falls back to en.
     */
    public function getLocalisedTreeData(): array
    {
        $result = [];
        foreach ($this->getDataForLocales($this->treeData) as $localeTree) {
            $result = $this->mergeTreeDataRecursively($result, $localeTree);
        }
        return $result;
    }

    /**
     * Get the URL slug for the given documentation file/folder path.
     */
    public function getSlugForPath(string $path, bool $isRootDir = false): string
    {
        if (!$isRootDir) {
            // Strip file extension and validate there's a root
            $dir = dirname($path);
            $file = pathinfo($path, PATHINFO_FILENAME);
            if ($dir === '.') {
                $path = $file;
            } elseif ($file === 'index') {
                $path = $dir;
            } else {
                $path = implode('/', [$dir, $file]);
            }
        }
        // Make lowercase and remove prefix numbering
        $segments = explode('/', $path);
        $parts = array_map(
            fn (string $segment): string => mb_strtolower(preg_replace('/^\d+_/', '', $segment)),
            $segments
        );
        // Join parts into a single slug path
        return implode('/', $parts);
    }

    /**
     * Get the data for each locale that applies to the current request, from lowest to highest precedence.
     */
    private function getDataForLocales(array $data): array
    {
        // reverse locale array so that the user locale has the highest precedence in the merge.
        $localesToCheck = array_reverse(static::getLocalePriority());
        $dataForLocales = [];
        foreach ($localesToCheck as $locale) {
            if (!empty($data[$locale])) {
                $dataForLocales[] = $data[$locale];
            }
        }
        return $dataForLocales;
    }

    /**
     * Merge each level of tree nodes together from different locales.
     * Nodes in $override replace nodes with the same key in $base, but their children are merged.
     */
    private function mergeTreeDataRecursively(array $base, array $override): array
    {
        foreach ($override as $key => $node) {
            $existing = $base[$key] ?? null;
            $children = $this->mergeTreeDataRecursively($existing['children'] ?? [], $node['children'] ?? []);
            $merged = ($existing && $this->isRootWithoutIndex($node)) ? $existing : $node;
            unset($merged['children']);
            if (!empty($children)) {
                $merged['children'] = $children;
            }
            $base[$key] = $merged;
        }
        return $base;
    }

    /**
     * A root folder without an index.md file only has placeholder data,
     * so it must not replace data for the same root from another locale or module.
     */
    private function isRootWithoutIndex(array $docDatum): bool
    {
        return !$docDatum['isIndex'] && !str_contains($docDatum['slug'], '/');
    }

    /**
     * Reload state from given cache data
     *
     * @return bool True if cache was valid and successfully loaded
     */
    protected function loadState(array $data): bool
    {
        $success = true;
        if (empty($data)) {
            return $success;
        }
        foreach ($this->cachedProperties as $property) {
            if (!isset($data[$property]) || !is_array($data[$property])) {
                $success = false;
                $value = [];
            } else {
                $value = $data[$property];
            }
            $this->$property = $value;
        }
        return $success;
    }

    private function collateTreeData(array $docData): array
    {
        // Find tree roots
        $roots = [];
        foreach ($docData as $locale => $localeData) {
            foreach ($localeData as $slug => $docDatum) {
                $root = explode('/', $slug)[0];
                if (!in_array($root, $roots[$locale] ?? [])) {
                    $roots[$locale][] = $root;
                }
            }
        }

        $treeData = [];
        $toCheck = $roots;
        foreach ($docData as $locale => $localeData) {
            foreach (ArrayLib::iterateVolatile($toCheck[$locale]) as $slugToCheck) {
                foreach ($localeData as $slug => $docDatum) {
                    // Roots need to be captured specifically
                    if (!str_contains($slugToCheck, '/') && $slug === $slugToCheck) {
                        $treeData[$locale][$slugToCheck]['slug'] = $slug;
                        $treeData[$locale][$slugToCheck]['title'] = $docDatum['title'];
                        $treeData[$locale][$slugToCheck]['isIndex'] = $docDatum['isIndex'];
                        $treeData[$locale][$slugToCheck]['hasContent'] = $docDatum['hasContent'];
                    }
                    // Capture children
                    if (str_contains($slug, '/') && $docDatum['parentSlug'] === $slugToCheck) {
                        $slugParts = explode('/', $slug);
                        $current = &$treeData[$locale];
                        $lastIndex = count($slugParts) - 1;
                        foreach ($slugParts as $index => $slugPart) {
                            // Ensure the current slug key exists as an array
                            if (!isset($current[$slugPart]) || !is_array($current[$slugPart])) {
                                $current[$slugPart] = [];
                            }

                            // If this is the final slug, write the value
                            if ($index === $lastIndex) {
                                // If the documentation has been set up in weird ways, log warnings.
                                // Note that only root nodes are allowed to lack content.
                                if (!$docDatum['hasContent']) {
                                    Injector::inst()->get(LoggerInterface::class)->warning(
                                        'Documentation at ' . $docDatum['filePath'] . ' has no content'
                                    );
                                }
                                $current[$slugPart] = [
                                    'slug' => $slug,
                                    'title' => $docDatum['title'],
                                    'isIndex' => $docDatum['isIndex'],
                                    'hasContent' => $docDatum['hasContent'],
                                ];
                                break;
                            }

                            // Ensure the 'children' key exists before navigating deeper
                            if (!isset($current[$slugPart]['children']) || !is_array($current[$slugPart]['children'])) {
                                $current[$slugPart]['children'] = [];
                            }

                            // Move the reference pointer down into 'children'
                            $current = &$current[$slugPart]['children'];
                        }

                        // Make sure we check for children of this page
                        $toCheck[$locale][] = $slug;
                    }
                }
            }
        }
        return $treeData;
    }

    /**
     * Convert a file name to a human-readable title
     * - Strips numeric prefixes (01_, 02_, etc.)
     * - Converts underscores to spaces
     * - For index files, uses parent directory name
     */
    private function fileNameToTitle(string $fileName, string $filePath): string
    {
        // Remove file extension
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        // Use directory name for index files
        if ($name === 'index') {
            $name = basename(dirname($filePath));
        }
        // Strip numeric prefixes
        $name = preg_replace('/^\d+_/', '', $name);
        // Convert underscores to spaces
        $name = preg_replace('/_/', ' ', $name);
        // Ensure sentence case, using mb_ functions to account for non-ASCII names
        $name = mb_strtolower($name);
        $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        return $name;
    }

    private function extractOrderFromFilename(string $fileName): ?int
    {
        if (!preg_match('/^(\d+)_/', $fileName, $matches)) {
            return null;
        }
        return (int) $matches[1];
    }

    private function buildCache(): ?CacheInterface
    {
        if ($this->cache) {
            return $this->cache;
        }
        return Injector::inst()->get(CacheInterface::class . '.userdocsmanifest');
    }
}
