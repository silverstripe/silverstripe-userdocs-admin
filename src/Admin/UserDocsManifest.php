<?php

namespace SilverStripe\UserDocs\Admin;

use Collator;
use InvalidArgumentException;
use Normalizer;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Manifest\ManifestFileFinder;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use Locale;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\Module;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
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
    use Configurable;
    use Injectable;

    protected const CACHE_KEY = 'manifest';

    /**
     * Paths to directories that contain user documentation.
     *
     * The key is the path and the value is a boolean which sets if the path is included.
     * Each path can be in "module:path" format, or relative to the project root.
     */
    private static array $documentation_roots = [];

    /**
     * Array of properties to cache
     */
    protected array $cachedProperties = [
        'docData',
    ];

    private ?CacheInterface $cache = null;

    /**
     * The full set of doc paths.
     * First key is the module name (e.g. "silverstripe/cms"), from the highest to the lowest priority module
     * Second key is the locale (e.g. "en")
     * Third key is the URL slug (e.g. "some-feature/sub-page")
     * Value is an array with metadata about the file including the full path
     */
    private array $docData = [];

    /**
     * Doc data for the current request, keyed by the list of locales it was resolved for.
     */
    private array $localisedDocData = [];

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
     */
    public function init(bool $includeTests = false): void
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
        $this->regenerate($this->getDocRoots(), $includeTests);
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
        $this->warnAboutDocsWithoutContent($docData);
        $data = [
            'docData' => $this->sortModulesByPriority($docData),
        ];
        $this->loadState($data);
        if ($this->cache) {
            $this->cache->set(static::CACHE_KEY, $data);
        }
    }

    /**
     * Sort the doc data for each module so the module with the highest priority comes first,
     * as determined by the module manifest.
     */
    private function sortModulesByPriority(array $data): array
    {
        // Use array_values() so array_search() returns positions, not the module name keys.
        $sortedModules = array_values(array_map(
            fn (Module $module) => $module->getName(),
            ModuleLoader::inst()->getManifest()->getModules()
        ));
        uksort($data, function (string $moduleA, string $moduleB) use ($sortedModules) {
            $posA = array_search($moduleA, $sortedModules);
            $posB = array_search($moduleB, $sortedModules);

            // If a module isn't in the module manifest, treat it as the least important module.
            // That *shouldn't* ever happen but it doesn't hurt to account for the edge case.
            if ($posA === false) {
                return 1;
            }
            if ($posB === false) {
                return -1;
            }
            return $posA <=> $posB;
        });
        return $data;
    }

    /**
     * Only root nodes are allowed to lack content, so log a warning for any other doc without content.
     */
    private function warnAboutDocsWithoutContent(array $data): void
    {
        foreach ($data as $moduleData) {
            foreach ($moduleData as $localeData) {
                foreach ($localeData as $docDatum) {
                    if (!$docDatum['hasContent'] && str_contains($docDatum['slug'], '/')) {
                        Injector::inst()->get(LoggerInterface::class)->warning(
                            'Documentation at ' . $docDatum['filePath'] . ' has no content'
                        );
                    }
                }
            }
        }
    }

    /**
     * Sort doc data into the order it's shown in the tree.
     *
     * @param string $locale The locale whose rules are used to compare text
     */
    private function sortDocData(array $docData, string $locale): array
    {
        // Compare text using the rules of the language, so case and accents don't put words out of order
        $collator = new Collator($locale);
        uasort($docData, function (array $a, array $b) use ($collator) {
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
                return $collator->compare($dirA, $dirB);
            }

            // If only one has order, it comes first
            if ($a['order'] !== null) {
                return -1;
            }
            if ($b['order'] !== null) {
                return 1;
            }

            // Compare alphabetically against file titles
            return $collator->compare($a['title'], $b['title']);
        });
        return $docData;
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

        if (!array_key_exists($locale, $data[$module] ?? [])) {
            $data[$module][$locale] = [];
        }

        // Make sure root node data is registered if it has no doc file.
        // Note we don't register branch nodes in the same way because those should always have associated docs.
        if (
            $parentSlug
            && !array_key_exists($parentSlug, $data[$module][$locale])
            && $parentSlug !== '.'
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
     * Get the doc data for every module and locale.
     * The data is keyed by module (from the highest to the lowest priority), then locale, then slug.
     */
    public function getState(?string $locale = null): array
    {
        return $this->docData;
    }

    /**
     * Get all data about documentation for the current locale.
     *
     * For each doc, the module with the highest priority that has the doc in any of the current locales is used.
     * Within that module, the current locale falls back to the shortened locale and then to the default locale.
     * For example pt_BR will fallback to pt which will then fallback to en_US which falls back to en.
     */
    public function getLocalisedDocData(): array
    {
        $locales = static::getLocalePriority();
        $key = implode(',', $locales);
        if (isset($this->localisedDocData[$key])) {
            return $this->localisedDocData[$key];
        }
        $result = [];
        foreach ($this->docData as $moduleData) {
            foreach ($locales as $locale) {
                foreach ($moduleData[$locale] ?? [] as $slug => $docDatum) {
                    // A doc from a higher priority module or locale wins,
                    // unless it's only a placeholder for a root folder without an index.md file
                    if (
                        !isset($result[$slug])
                        || ($this->isRootWithoutIndex($result[$slug]) && !$this->isRootWithoutIndex($docDatum))
                    ) {
                        $result[$slug] = $docDatum;
                    }
                }
            }
        }
        $this->localisedDocData[$key] = $this->sortDocData($result, $locales[0]);
        return $this->localisedDocData[$key];
    }

    /**
     * Get the hierarchical-structured tree data for the current locale.
     * Uses the same docs as getLocalisedDocData().
     */
    public function getLocalisedTreeData(): array
    {
        return $this->collateTreeData($this->getLocalisedDocData());
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
            // Check for index first, so "index.md" resolves to its folder ("."), not to an "index" page
            if ($file === 'index') {
                $path = $dir;
            } elseif ($dir === '.') {
                $path = $file;
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
        // Join parts into a single slug path.
        $slug = implode('/', $parts);
        // Use the composed (NFC) form, so "é" as one character and as "e" plus an accent give the same slug.
        return Normalizer::normalize($slug, Normalizer::FORM_C) ?: $slug;
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
        $this->localisedDocData = [];
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

    /**
     * Build the tree of links for the given doc data.
     *
     * @param array $docData Doc data keyed by slug, in the order it's shown in the tree
     */
    private function collateTreeData(array $docData): array
    {
        // Group docs by parent so each level is built without scanning every doc
        $roots = [];
        $childrenByParent = [];
        foreach ($docData as $slug => $docDatum) {
            // PHP makes numeric slugs like "2024" into int array keys
            $slug = (string) $slug;
            $roots[explode('/', $slug)[0]] = true;
            if (str_contains($slug, '/')) {
                $childrenByParent[$docDatum['parentSlug']][] = $docDatum;
            }
        }
        $treeData = [];
        foreach (array_keys($roots) as $root) {
            $node = $this->getTreeNode($docData[$root]);
            $children = $this->collateTreeChildren((string) $root, $childrenByParent);
            if (!empty($children)) {
                $node['children'] = $children;
            }
            $treeData[$root] = $node;
        }
        return $treeData;
    }

    /**
     * Build the tree nodes for the children of the given parent, including their own children.
     *
     * @param array $childrenByParent Doc data for each non-root doc, grouped by parent slug
     */
    private function collateTreeChildren(string $parentSlug, array $childrenByParent): array
    {
        $children = [];
        foreach ($childrenByParent[$parentSlug] ?? [] as $docDatum) {
            $node = $this->getTreeNode($docDatum);
            $grandchildren = $this->collateTreeChildren($docDatum['slug'], $childrenByParent);
            if (!empty($grandchildren)) {
                $node['children'] = $grandchildren;
            }
            $children[basename($docDatum['slug'])] = $node;
        }
        return $children;
    }

    private function getTreeNode(array $docDatum): array
    {
        return [
            'slug' => $docDatum['slug'],
            'title' => $docDatum['title'],
            'isIndex' => $docDatum['isIndex'],
            'hasContent' => $docDatum['hasContent'],
        ];
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

    private function getDocRoots(): array
    {
        $roots = [];
        foreach (static::config()->get('documentation_roots') as $dir => $include) {
            if (is_int($dir)) {
                $dir = $include;
                $include = true;
            }
            if (!$include) {
                continue;
            }
            $roots[] = FilesystemPath::makeAbsolute(ModuleResourceLoader::singleton()->resolvePath($dir), BASE_PATH);
        }
        return $roots;
    }
}
