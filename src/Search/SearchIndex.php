<?php

namespace SilverStripe\UserDocs\Search;

use BadMethodCallException;
use Error;
use InvalidArgumentException;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\Embed\Embed;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContents;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContentsPlaceholder;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\RawMarkupContainerInterface;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use Locale;
use Override;
use PDO;
use Predis\Client as PredisClient;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use TeamTNT\TNTSearch\Engines\MysqlEngine;
use TeamTNT\TNTSearch\Engines\RedisEngine;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;
use TeamTNT\TNTSearch\TNTSearch;
use Throwable;

/**
 * Builds and searches full text indexes of the user documentation.
 *
 * Each locale has its own index for the body text of its pages, plus one index for each weighted zone
 * (see the zone_weights configuration). Page data such as titles and file paths comes from UserDocsManifest.
 *
 * Searches use the locale priority from UserDocsManifest::getLocalePriority(). A page is only found
 * in its effective locale, which is the first locale in that priority that has a version of the page.
 */
class SearchIndex implements Flushable
{
    use Extensible;
    use Configurable;
    use Injectable;

    protected const CACHE_KEY = 'indexmap';

    /**
     * The maximum number of indexed words to keep for each stem.
     * This stops a long search query from becoming too large when it is expanded.
     */
    private const MAX_STEM_VARIANTS = 10;

    /**
     * Score weights for matches in specific zones of a page.
     *
     * A zone is a part of the page's text that is indexed and scored separately from the body text,
     * for example the title or the text of all level 2 headings. Text in a weighted zone does not count
     * as body text. A match in a zone adds its score multiplied by the zone's weight to the body text score.
     * Body text has a weight of 1, so a weight above 1 makes a zone more important than body text,
     * and a weight below 1 makes it less important.
     *
     * Valid zones are "title" and heading levels "h1" to "h6".
     * Set a zone to null to count its text as body text.
     * If "title" is null, filtering by title matches against the page titles directly.
     */
    private static array $zone_weights = [
        'title' => 5,
        'h1' => 4,
        'h2' => 2,
        'h3' => 1,
    ];

    /**
     * Words to ignore in search, keyed by locale (e.g. "en_NZ" or "en").
     * Locales fall back to their short form - e.g. if there's no stop words in
     * "en_NZ", it'll fall back to checking "en".
     */
    private static array $stop_words = [];

    /**
     * Stemmer classes keyed by short form locale (e.g. "en", never "en_NZ").
     * Each class must implement TeamTNT\TNTSearch\Stemmer\StemmerInterface.
     *
     * Locales with no stemmer class match only the exact search terms.
     */
    private static array $stemmers = [];

    /**
     * Corrections for related words that the stemmer gives different stems, keyed by short form locale
     * (e.g. "en", never "en_NZ"). Each locale has an array that maps an incorrect stem to the correct stem.
     *
     * For example, the porter stemmer gives "add" the stem "add", but gives "adding" the stem "ad".
     * The alias "ad" => "add" makes a search for "add" also match "adding", and vice versa.
     */
    private static array $stemmer_aliases = [];

    /**
     * Classes of markdown nodes to remove before indexing, so their text doesn't count towards any index.
     * Subclasses of these classes are also removed, as are any nodes nested as children of these nodes.
     */
    private static array $excluded_node_classes = [
        Embed::class,
        HeadingPermalink::class,
        TableOfContents::class,
        TableOfContentsPlaceholder::class,
    ];

    /**
     * If true, disables the default max results limit in the underlying search engine.
     * Setting this to false will limit the number of results returned and can affect
     * page relevancy rankings.
     *
     * Only set this to false if you have explicit performance limitations that aren't
     * easily resolved any other way (which is very unlikely).
     */
    private static bool $disable_search_limit = true;

    /**
     * The length of the text returned by createSummary()
     */
    private static int $summary_length = 300;

    /**
     * @internal
     */
    private static ?CacheInterface $cache = null;

    private ?TNTSearch $tntSearch = null;

    private ?bool $isSupported = null;

    /**
     * Details about the indexes that have been built.
     * "locales" are the locales that have indexes, and "checkedLocales" are all locales that init() has
     * processed, including locales with no content to index.
     * "slugs" are the sorted slugs of all pages when indexing started. The ID of a page is its position + 1.
     */
    private array $indexMap = [
        'locales' => [],
        'checkedLocales' => [],
        'zones' => [],
        'slugs' => [],
    ];

    /**
     * Cached per locale under "stemvariants.{$locale}". Keys are canonical stems; values are
     * the indexed words with that stem, most common first.
     * e.g. ['publish' => ['published', 'publish', 'publishing'], 'add' => ['add', 'added', 'adding']]
     */
    private array $indexedStemVariants = [];

    /**
     * Page IDs keyed by page slug, built from the index map when first needed.
     */
    private array $pageIdsBySlug = [];

    /**
     * @inheritDoc
     */
    #[Override]
    public static function flush(): void
    {
        $singleton = static::singleton();
        if ($singleton->isSupported()) {
            $singleton->clearIndex();
        }
    }

    /**
     * Check if this server has the dependencies that the configured tntsearch engine needs.
     * Engines other than SqliteEngine, MysqlEngine, and RedisEngine are assumed to be supported.
     */
    public function isSupported(): bool
    {
        if ($this->isSupported === null) {
            try {
                $engine = $this->getTntSearch()->engine;
            } catch (Error $error) {
                // RedisEngine creates a Predis client when it loads its config, which fails if the class is missing.
                $missingPredis = sprintf('Class "%s" not found', PredisClient::class);
                if ($error->getMessage() === $missingPredis && !$this->hasPredisClient()) {
                    return false;
                }
                throw $error;
            }
            // MysqlEngine is a subclass of SqliteEngine, so check it first.
            $isSupported = match (true) {
                $engine instanceof MysqlEngine => in_array('mysql', $this->getAvailablePdoDrivers()),
                $engine instanceof SqliteEngine => in_array('sqlite', $this->getAvailablePdoDrivers()),
                $engine instanceof RedisEngine => $this->hasPredisClient(),
                // We can't check engines we aren't aware of so we must assume they're supported.
                // Custom implementations can be handled in an extension.
                default => true,
            };
            $this->extend('updateIsSupported', $isSupported, $engine);
            $this->isSupported = $isSupported;
        }
        return $this->isSupported;
    }

    public function init(): void
    {
        $this->removeMissingIndexes();
        // getIndexMap() would reload the cache if removing missing indexes left no checked locales
        $indexMap = $this->indexMap;
        if (empty($indexMap['slugs'])) {
            $this->indexMap['slugs'] = $this->getAllSlugs();
            $this->pageIdsBySlug = [];
        }
        $weightedZones = array_keys($this->getZoneWeights());
        foreach (UserDocsManifest::getLocalePriority() as $locale) {
            if (in_array($locale, $indexMap['checkedLocales'])) {
                // Skip locales we've already indexed or found to have no content
                continue;
            }
            $this->indexMap['checkedLocales'][] = $locale;
            if ($this->indexLocale($locale, $weightedZones)) {
                $this->indexMap['locales'][] = $locale;
                $this->indexMap['zones'] = $weightedZones;
            }
        }
        // Cache the index map so we can skip re-indexing in future runs
        Injector::inst()->get(CacheInterface::class . '.userdocssearch')->set(static::CACHE_KEY, $this->indexMap);
    }

    /**
     * Check if init() has built the indexes for all locales in the current locale priority.
     * The indexes must also exist, because the index map can be shared by servers that each have their own indexes.
     */
    public function isBuilt(): bool
    {
        $checkedLocales = $this->getIndexMap()['checkedLocales'];
        if (!empty(array_diff(UserDocsManifest::getLocalePriority(), $checkedLocales))) {
            return false;
        }
        foreach ($this->getIndexedLocalesInPriority() as $locale) {
            if (!$this->hasIndex($locale)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Search the documentation, highest score first.
     *
     * @param array $query Search terms keyed by form field(s) from `SearchForm`.
     * Terms from the default field will search across all indexes.
     * Terms from other fields in that form will filter results by relevant indexes.
     *
     * @throws BadMethodCallException if the indexes for the current locales have not been built with init()
     */
    public function search(array $query): array
    {
        if (!$this->isBuilt()) {
            throw new BadMethodCallException('The search indexes for the current locales have not been built yet');
        }
        $terms = $this->getQueryValue($query, SearchForm::DEFAULT_SEARCH_FIELD);
        $title = $this->getQueryValue($query, 'title');
        $results = [];
        if ($title !== '') {
            $results = in_array('title', $this->getIndexMap()['zones'])
                ? $this->searchLocales($title, ['title'], false)
                : $this->searchManifestTitles($title);
        }
        if ($terms !== '') {
            $termResults = $this->searchLocales($terms);
            $results = $title !== ''
                ? array_intersect_key($termResults, $results)
                : $termResults;
        }
        // Sort final merged results by score
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return $results;
    }

    /**
     * Get a suggested query with each word that isn't indexed replaced by the closest indexed word.
     * Words are checked against all of the indexes that search() uses.
     * The suggestion uses the words as the tokenizer returns them, so it is lower case and has no punctuation.
     * Returns null if no word changes.
     *
     * @throws BadMethodCallException if the indexes for the current locales have not been built with init()
     */
    public function getQueryRecommendation(string $originalQuery): ?string
    {
        if (!$this->isBuilt()) {
            throw new BadMethodCallException('The search indexes for the current locales have not been built yet');
        }
        $indexNames = $this->getIndexNamesInPriority();
        $words = $this->getTntSearch()->breakIntoTokens($originalQuery);
        // Stop words aren't indexed, so keep them as they are instead of finding a close match.
        // Use the stop words for all locales, because the indexes for all locales are checked.
        $stopWords = [];
        foreach ($this->getIndexedLocalesInPriority() as $locale) {
            $stopWords = array_merge($stopWords, $this->getStopWords($locale));
        }
        $changed = false;
        foreach ($words as $i => $word) {
            if (in_array($word, $stopWords, true) || $this->hasStemVariants($word)) {
                continue;
            }
            $recommendation = $this->getWordRecommendation($word, $indexNames);
            if ($recommendation !== null && $recommendation !== $word) {
                $words[$i] = $recommendation;
                $changed = true;
            }
        }
        return $changed ? implode(' ', $words) : null;
    }

    /**
     * Get an HTML summary of the page with the given ID, showing where its text matches the query.
     * Returns an empty string if the page or its file can't be found.
     *
     * @param int $id The "id" of a result from search()
     */
    public function createSummary(string $query, int $id, bool $highlight = true): string
    {
        $locale = $this->getEffectiveLocale($id);
        $pageData = $locale ? $this->getPageData($id, $locale) : null;
        if (!$pageData) {
            return '';
        }
        // Use all text except excluded nodes, so matches in headings are included in the summary.
        $text = $this->getNodeText($this->parseDocument($pageData['filePath']));
        // Remove stop words so they don't affect where the summary starts and aren't highlighted.
        // Add words with the same stem so the summary shows the words that matched the search.
        $query = implode(' ', $this->expandQuery($query, $locale));
        // Prevcount uses 1/6 ratio of snippet length as recommended by the tntsearch docs
        $snippetLength = static::config()->get('summary_length');
        $prevCount = (int) ($snippetLength / 6);
        // We need to suppress warnings because the snippet method often results in a deprecation warning.
        // See https://github.com/teamtnt/tntsearch/issues/370
        $summary = @$this->getTntSearch()->snippet($query, $text, $snippetLength, $prevCount);
        // The text can contain HTML characters from code and entities, so escape it before adding highlight tags.
        $summary = htmlspecialchars($summary, ENT_NOQUOTES);
        if ($highlight) {
            $summary = $this->getTntSearch()->highlight($summary, $query);
        }
        return $summary;
    }

    /**
     * Clear all records out of the index.
     */
    public function clearIndex(): void
    {
        // Clear indexes first
        $tnt = $this->getTntSearch();
        $indexMap = $this->getIndexMap();
        foreach ($indexMap['locales'] as $locale) {
            $tnt->engine->flushIndex($this->getIndexName($locale));
            foreach ($indexMap['zones'] as $zone) {
                $tnt->engine->flushIndex($this->getIndexName($locale, $zone));
            }
        }
        // Clear cached index map
        Injector::inst()->get(CacheInterface::class . '.userdocssearch')->clear();
        $this->indexMap = [
            'locales' => [],
            'checkedLocales' => [],
            'zones' => [],
            'slugs' => [],
        ];
        $this->indexedStemVariants = [];
        $this->pageIdsBySlug = [];
    }

    /**
     * Check if the body text index for the locale exists.
     * Zone indexes are built with the body text index, so they don't need to be checked.
     * Engines that don't check if an index exists when it is selected, such as RedisEngine, always return true.
     */
    private function hasIndex(string $locale): bool
    {
        try {
            $this->getTntSearch()->selectIndex($this->getIndexName($locale));
        } catch (IndexNotFoundException) {
            return false;
        }
        return true;
    }

    /**
     * Remove the locales in the current locale priority that have no body text index from the index map,
     * so that init() builds their indexes again.
     */
    private function removeMissingIndexes(): void
    {
        foreach ($this->getIndexedLocalesInPriority() as $locale) {
            if ($this->hasIndex($locale)) {
                continue;
            }
            $this->indexMap['locales'] = array_values(array_diff($this->indexMap['locales'], [$locale]));
            $this->indexMap['checkedLocales'] = array_values(
                array_diff($this->indexMap['checkedLocales'], [$locale])
            );
        }
    }

    /**
     * Resolves which locale should serve this page based on the fallback chain for the current locale.
     * Only the module that the manifest uses for the page in the current locale can serve it,
     * so a translation from a module with a lower priority doesn't hide an override.
     */
    protected function getEffectiveLocale(int $pageId): ?string
    {
        $slug = $this->getIndexMap()['slugs'][$pageId - 1] ?? null;
        if ($slug === null) {
            return null;
        }
        $module = UserDocsManifest::singleton()->getLocalisedDocData()[$slug]['module'] ?? null;
        foreach (UserDocsManifest::getLocalePriority() as $locale) {
            $pageData = $this->getPageData($pageId, $locale);
            if ($pageData && ($pageData['module'] ?? null) === $module) {
                return $locale;
            }
        }
        return null;
    }

    /**
     * Get the ID of the page with the given slug, or null if the page wasn't in the manifest when indexing started.
     * tntsearch needs int IDs, and the ID must be the same in all locales for the same slug.
     */
    private function getPageId(string $slug): ?int
    {
        if (empty($this->pageIdsBySlug)) {
            foreach ($this->getIndexMap()['slugs'] ?? [] as $position => $pageSlug) {
                $this->pageIdsBySlug[$pageSlug] = $position + 1;
            }
        }
        return $this->pageIdsBySlug[$slug] ?? null;
    }

    /**
     * Get the unique slugs of the pages in all locales, sorted so that page IDs don't depend on locale order.
     *
     * @return string[]
     */
    private function getAllSlugs(): array
    {
        $slugs = [];
        foreach (UserDocsManifest::singleton()->getDocData() as $localeData) {
            foreach (array_keys($localeData) as $slug) {
                $slugs[$slug] = true;
            }
        }
        // Numeric slugs are int array keys
        $slugs = array_map('strval', array_keys($slugs));
        sort($slugs, SORT_STRING);
        return $slugs;
    }

    /**
     * Get the manifest data for the page with the given ID in the given locale.
     * Returns null if that locale has no indexable version of the page.
     */
    private function getPageData(int $pageId, string $locale): ?array
    {
        $slug = $this->getIndexMap()['slugs'][$pageId - 1] ?? null;
        $docDatum = $slug !== null ? UserDocsManifest::singleton()->getDocData($locale)[$slug] ?? null : null;
        if (!$docDatum || !$this->isIndexable($docDatum)) {
            return null;
        }
        return $docDatum;
    }

    /**
     * Build the body text index and the zone indexes for the locale.
     * Returns false if the locale has no indexable pages.
     */
    private function indexLocale(string $locale, array $zones): bool
    {
        $contentRows = [];
        $zoneRows = array_fill_keys($zones, []);
        // Note we explicitly don't use locale cascading here - each index should have ONLY
        // the content for the given locale (if any). We cascade during search.
        foreach (UserDocsManifest::singleton()->getDocData($locale) as $docDatum) {
            if (!$this->isIndexable($docDatum)) {
                // Just silently skip any with no content or missing file.
                // Warnings etc for these are handled elsewhere.
                continue;
            }
            $id = $this->getPageId($docDatum['slug']);
            if ($id === null) {
                continue;
            }
            [$bodyText, $textByZone] = $this->getTextForPage($docDatum, $zones);
            $contentRows[] = [
                'id' => $id,
                'text' => $bodyText,
            ];
            foreach ($textByZone as $zone => $text) {
                // Add every page to every zone index, even with no text. This keeps the document
                // count the same in all indexes which is important for tntsearch's relevancy calculations
                $zoneRows[$zone][] = [
                    'id' => $id,
                    'text' => $text,
                ];
            }
        }
        if (empty($contentRows)) {
            return false;
        }
        // Build indexes here instead of above to avoid switching index files constantly
        $stopWords = $this->getStopWords($locale);
        $this->buildIndex($this->getIndexName($locale), $contentRows, $stopWords);
        foreach ($zoneRows as $zone => $rows) {
            $this->buildIndex($this->getIndexName($locale, $zone), $rows, $stopWords);
        }
        $this->cacheStemVariants($locale, $contentRows, $zoneRows);
        return true;
    }

    /**
     * Get the body text of the page and the text of each of the given zones.
     * The page title is in the body text if "title" is not one of the zones.
     *
     * @return array The body text, then the text of each zone keyed by zone name
     */
    private function getTextForPage(array $docDatum, array $zones): array
    {
        $document = $this->parseDocument($docDatum['filePath']);
        // Get zone text first, because this removes the zone nodes from the document.
        $textByZone = $this->extractTextForZones($zones, $docDatum['title'], $document);
        $bodyText = $this->getNodeText($document);
        if (!array_key_exists('title', $textByZone)) {
            $bodyText = $docDatum['title'] . "\n" . $bodyText;
        }
        return [$bodyText, $textByZone];
    }

    private function isIndexable(array $docDatum): bool
    {
        return $docDatum['hasContent'] && file_exists($docDatum['filePath']);
    }

    private function getIndexMap(): array
    {
        // Every locale init() processes is checked, including locales with nothing to index
        if (empty($this->indexMap['checkedLocales'])) {
            $this->indexMap = Injector::inst()->get(CacheInterface::class . '.userdocssearch')->get(static::CACHE_KEY, [
                'locales' => [],
                'checkedLocales' => [],
                'zones' => [],
                'slugs' => [],
            ]);
            // Page IDs come from the slugs in the map
            $this->pageIdsBySlug = [];
        }
        return $this->indexMap;
    }

    /**
     * Get the configured zone weights, without zones that count as body text.
     */
    private function getZoneWeights(): array
    {
        return array_filter(static::config()->get('zone_weights') ?? []);
    }

    /**
     * Get the configured stop words for the locale.
     * If the locale has no stop words, the stop words for its primary language are used.
     */
    private function getStopWords(string $locale): array
    {
        $stopWords = static::config()->get('stop_words') ?? [];
        return $stopWords[$locale] ?? $stopWords[Locale::getPrimaryLanguage($locale) ?? ''] ?? [];
    }

    /**
     * Split text into words the same way as the indexer, without the stop words for the locale.
     */
    private function tokenize(string $text, string $locale): array
    {
        return $this->getTntSearch()->tokenizer->tokenize($text, $this->getStopWords($locale));
    }

    /**
     * Get the configured stemmer class for the locale, or null if the locale has no stemmer.
     */
    private function getStemmerClass(string $locale): ?string
    {
        $stemmers = static::config()->get('stemmers');
        return $stemmers[Locale::getPrimaryLanguage($locale) ?? ''] ?? null;
    }

    /**
     * Get the stem of each word, with the stemmer aliases for the locale applied.
     * The keys of the returned array are the same as the keys of $words.
     * If the locale has no stemmer, the words are returned as they are.
     */
    private function getStems(array $words, string $locale): array
    {
        $stemmerClass = $this->getStemmerClass($locale);
        if ($stemmerClass === null) {
            return $words;
        }
        $allAliases = static::config()->get('stemmer_aliases') ?? [];
        $aliases = $allAliases[Locale::getPrimaryLanguage($locale) ?? ''] ?? [];
        $stems = [];
        foreach ($words as $key => $word) {
            $stem = $stemmerClass::stem($word);
            $stems[$key] = $aliases[$stem] ?? $stem;
        }
        return $stems;
    }

    /**
     * Get the indexed words for each stem in the text, without stop words.
     * Words are sorted with the most common first.
     */
    private function buildStemVariants(array $texts, string $locale): array
    {
        $wordCounts = [];
        foreach ($texts as $text) {
            foreach ($this->tokenize($text, $locale) as $word) {
                $wordCounts[$word] = ($wordCounts[$word] ?? 0) + 1;
            }
        }
        arsort($wordCounts);
        $words = array_keys($wordCounts);
        $stems = $this->getStems($words, $locale);
        $stemVariants = [];
        foreach ($words as $i => $word) {
            $stem = $stems[$i];
            if (count($stemVariants[$stem] ?? []) < static::MAX_STEM_VARIANTS) {
                $stemVariants[$stem][] = (string) $word;
            }
        }
        return $stemVariants;
    }

    /**
     * Build the stem variants for the text of all rows in the indexes for the locale, and cache them.
     * Does nothing if the locale has no stemmer.
     */
    private function cacheStemVariants(string $locale, array $contentRows, array $zoneRows): void
    {
        if ($this->getStemmerClass($locale) === null) {
            return;
        }
        $texts = array_column($contentRows, 'text');
        foreach ($zoneRows as $rows) {
            foreach ($rows as $row) {
                $texts[] = $row['text'];
            }
        }
        $stemVariants = $this->buildStemVariants($texts, $locale);
        $this->indexedStemVariants[$locale] = $stemVariants;
        Injector::inst()->get(CacheInterface::class . '.userdocssearch')
            ->set($this->getStemVariantsCacheKey($locale), $stemVariants);
    }

    /**
     * Get the cached stem variants for the locale.
     * Returns an empty array if the locale has no stemmer or no cached stem variants.
     */
    private function getStemVariants(string $locale): array
    {
        if (!array_key_exists($locale, $this->indexedStemVariants)) {
            $this->indexedStemVariants[$locale] = Injector::inst()->get(CacheInterface::class . '.userdocssearch')
                ->get($this->getStemVariantsCacheKey($locale), []);
        }
        return $this->indexedStemVariants[$locale];
    }

    /**
     * Check if the word has the same stem as indexed words in any of the indexed locales.
     * A search for this word finds those indexed words, so it doesn't need a recommendation.
     */
    private function hasStemVariants(string $word): bool
    {
        foreach ($this->getIndexedLocalesInPriority() as $locale) {
            $stem = $this->getStems([$word], $locale)[0];
            if (array_key_exists($stem, $this->getStemVariants($locale))) {
                return true;
            }
        }
        return false;
    }

    private function getStemVariantsCacheKey(string $locale): string
    {
        return "stemvariants.{$locale}";
    }

    /**
     * Split the query into words without stop words, and add the indexed words that have the same stem
     * as each word. If the locale has no stemmer, only the words of the query are returned.
     */
    private function expandQuery(string $query, string $locale): array
    {
        $words = $this->tokenize($query, $locale);
        if ($this->getStemmerClass($locale) === null) {
            return $words;
        }
        $stemVariants = $this->getStemVariants($locale);
        $expandedWords = [];
        foreach ($this->getStems($words, $locale) as $i => $stem) {
            $expandedWords[] = $words[$i];
            foreach ($stemVariants[$stem] ?? [] as $variant) {
                $expandedWords[] = $variant;
            }
        }
        return array_values(array_unique($expandedWords));
    }

    /**
     * Parse the markdown file and remove excluded nodes from the document.
     */
    private function parseDocument(string $filePath): Document
    {
        /** @var MarkdownConverter $converter */
        $converter = Injector::inst()->create(MarkdownConverter::class . '.userdocs');
        $parser = Injector::inst()->create(MarkdownParser::class, $converter->getEnvironment());
        $document = $parser->parse(file_get_contents($filePath));
        $this->removeExcludedNodes($document);
        return $document;
    }

    /**
     * Remove nodes of the classes in the excluded_node_classes configuration from the document.
     */
    private function removeExcludedNodes(Document $document): void
    {
        $excludedClasses = static::config()->get('excluded_node_classes') ?? [];
        $excludedNodes = [];
        foreach ($document->iterator() as $node) {
            foreach ($excludedClasses as $class) {
                if ($node instanceof $class) {
                    $excludedNodes[] = $node;
                    break;
                }
            }
        }
        foreach ($excludedNodes as $node) {
            $node->detach();
        }
    }

    /**
     * Get the text of each of the given zones, keyed by zone name, and remove the zone nodes from the document.
     * A zone with no text in the page has an empty string.
     */
    private function extractTextForZones(array $zones, string $title, Document $document): array
    {
        $textByZone = array_fill_keys($zones, '');
        if (array_key_exists('title', $textByZone)) {
            $textByZone['title'] = $title;
        }
        $zoneNodes = [];
        foreach ($document->iterator() as $node) {
            if (!$node instanceof Heading) {
                continue;
            }
            $zone = 'h' . $node->getLevel();
            if (array_key_exists($zone, $textByZone)) {
                $textByZone[$zone] = ltrim($textByZone[$zone] . "\n" . $this->getNodeText($node));
                $zoneNodes[] = $node;
            }
        }
        foreach ($zoneNodes as $node) {
            $node->detach();
        }
        return $textByZone;
    }

    /**
     * Get the plain text of a node and its children.
     * Adds a space between blocks and lines so that words don't merge, and strips tags from raw HTML.
     */
    private function getNodeText(Node $node): string
    {
        $text = '';
        foreach ($node->iterator() as $child) {
            if ($child instanceof AbstractBlock || $child instanceof Newline) {
                // Ensure blocks of text don't run into each other
                $text .= ' ';
            }
            if ($child instanceof RawMarkupContainerInterface) {
                // Strip raw HTML from the markdown content
                $text .= strip_tags($child->getLiteral());
            } elseif ($child instanceof StringContainerInterface) {
                $text .= $child->getLiteral();
            }
        }
        return trim($text);
    }

    private function buildIndex(string $indexName, array $rows, array $stopWords): void
    {
        $tnt = $this->getTntSearch();
        $indexer = $tnt->createIndex($indexName);
        $indexer->setStopWords($stopWords);
        // Insert all rows in one transaction where possible.
        // Otherwise each row is committed separately, which is much slower.
        $pdo = $tnt->engine instanceof SqliteEngine ? $tnt->engine->index : null;
        $pdo?->beginTransaction();
        try {
            foreach ($rows as $row) {
                $indexer->insert($row);
            }
            $pdo?->commit();
        } catch (Throwable $exception) {
            if ($pdo?->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        // Stop words are actually set on the engine, despite the call being in the indexer.
        // This means the stop words could persist across locales if not reset.
        $indexer->setStopWords([]);
    }

    /**
     * Get the trimmed value of a field in a search query.
     * Returns an empty string if the value is not a string, for example an array from "?q[title][]=value".
     */
    private function getQueryValue(array $query, string $field): string
    {
        $value = $query[$field] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Search the indexes for all locales in the locale priority, keyed by page ID.
     * Each page is only found in its effective locale.
     * See searchLocale() for the $zones and $includeBody parameters.
     */
    private function searchLocales(string $query, ?array $zones = null, bool $includeBody = true): array
    {
        $results = [];
        foreach ($this->getIndexedLocalesInPriority() as $priorityIndex => $locale) {
            foreach ($this->searchLocale($query, $locale, $zones, $includeBody) as $pageId => $score) {
                // 1. Skip if already captured by a higher-priority locale in this search
                if (isset($results[$pageId])) {
                    continue;
                }

                // 2. CRUCIAL: Result Shadowing Check
                // If this page was found in 'en', but has a 'fr' version,
                // its effective locale is 'fr'. It MUST NOT appear as an English result.
                if ($this->getEffectiveLocale($pageId) !== $locale) {
                    continue;
                }

                $results[$pageId] = $this->createResult($pageId, $locale, $priorityIndex, $score);
            }
        }
        return $results;
    }

    /**
     * Get the locales in the current locale priority that have indexes.
     * The keys are the position of each locale in the locale priority.
     */
    private function getIndexedLocalesInPriority(): array
    {
        $indexedLocales = $this->getIndexMap()['locales'];
        return array_filter(
            UserDocsManifest::getLocalePriority(),
            fn(string $locale) => in_array($locale, $indexedLocales)
        );
    }

    /**
     * Get the names of all indexes built for the locales in the current locale priority,
     * including the zone indexes.
     */
    private function getIndexNamesInPriority(): array
    {
        $zones = $this->getIndexMap()['zones'];
        $indexNames = [];
        foreach ($this->getIndexedLocalesInPriority() as $locale) {
            $indexNames[] = $this->getIndexName($locale);
            foreach ($zones as $zone) {
                $indexNames[] = $this->getIndexName($locale, $zone);
            }
        }
        return $indexNames;
    }

    /**
     * Get the name of the body text index for the given locale, or of one of its zone indexes.
     */
    private function getIndexName(string $locale, ?string $zone = null): string
    {
        return $zone === null ? "{$locale}.index" : "{$locale}.{$zone}.index";
    }

    /**
     * Get the closest indexed word to the given word across the given indexes.
     * Matches are ordered by edit distance, then by the number of hits across all of the indexes.
     * Returns the word itself if it is indexed, or null if there are no close matches.
     */
    private function getWordRecommendation(string $word, array $indexNames): ?string
    {
        $tnt = $this->getTntSearch();
        $matches = [];
        foreach ($indexNames as $indexName) {
            $tnt->selectIndex($indexName);
            foreach ($tnt->fuzzySearch($word) as $match) {
                $term = $match['term'];
                $matches[$term] = [
                    'distance' => $match['distance'],
                    'hits' => ($matches[$term]['hits'] ?? 0) + $match['num_hits'],
                ];
            }
        }
        if (empty($matches)) {
            return null;
        }
        uasort($matches, fn($a, $b) => [$a['distance'], $b['hits']] <=> [$b['distance'], $a['hits']]);
        return (string) array_key_first($matches);
    }

    /**
     * Search the indexes for the given locale.
     * Returns the score of each matching page keyed by page ID, highest first.
     * Matches in weighted zones add to the body text score.
     *
     * @param null|array $zones The specific zone(s) to search. If null, all zones will
     * be searched. If empty, only the main index will be searched.
     * @param bool $includeBody If false, the main index is not searched.
     */
    private function searchLocale(string $query, string $locale, ?array $zones = null, bool $includeBody = true): array
    {
        if (!$includeBody && $zones === []) {
            throw new InvalidArgumentException('At least one zone or the main index must be searched');
        }
        $query = implode(' ', $this->expandQuery($query, $locale));
        if ($query === '') {
            return [];
        }
        $tnt = $this->getTntSearch();
        $tnt->selectIndex($this->getIndexName($locale));
        $scores = [];
        // Search in body index
        if ($includeBody) {
            $scores = $tnt->search($query)['docScores'];
        }
        $zoneWeights = $this->getZoneWeights();
        // Search in zone indexes
        foreach ($this->getIndexMap()['zones'] as $zone) {
            if ($zones !== null && !in_array($zone, $zones)) {
                continue;
            }
            $tnt->selectIndex($this->getIndexName($locale, $zone));
            foreach ($tnt->search($query)['docScores'] as $pageId => $score) {
                $scores[$pageId] = ($scores[$pageId] ?? 0) + $score * ($zoneWeights[$zone] ?? 1);
            }
        }
        arsort($scores);
        return $scores;
    }

    /**
     * Get the pages whose title contains any word in the query, keyed by page ID.
     * Each page is only found in its effective locale, and its score is the number of words that match.
     * Use this to filter by title when titles are not indexed separately.
     */
    private function searchManifestTitles(string $query): array
    {
        $results = [];
        $locales = UserDocsManifest::getLocalePriority();
        foreach ($locales as $priorityIndex => $locale) {
            // Compare stems, so that word forms match in the same way as in the indexes.
            $queryStems = $this->getStems($this->tokenize($query, $locale), $locale);
            foreach (UserDocsManifest::singleton()->getDocData($locale) as $docDatum) {
                $pageId = $this->getPageId($docDatum['slug']);
                if (
                    $pageId === null
                    || isset($results[$pageId])
                    || $this->getEffectiveLocale($pageId) !== $locale
                ) {
                    continue;
                }
                $titleStems = $this->getStems($this->getTntSearch()->breakIntoTokens($docDatum['title']), $locale);
                $score = count(array_filter($queryStems, fn($stem) => in_array($stem, $titleStems)));
                if ($score > 0) {
                    $results[$pageId] = $this->createResult($pageId, $locale, $priorityIndex, $score);
                }
            }
        }
        return $results;
    }

    /**
     * Create a search result for the page with the given ID in its effective locale.
     *
     * @param int $priorityIndex The position of the locale in the locale priority
     */
    private function createResult(int $pageId, string $locale, int $priorityIndex, float $score): array
    {
        $pageMeta = $this->getPageData($pageId, $locale);
        return [
            'id' => $pageId,
            'title' => $pageMeta['title'],
            'slug' => $pageMeta['slug'],
            'matched_locale' => $locale,
            'is_fallback' => $priorityIndex > 0,
            // Score with a small priority discount for fallback locales
            'score' => $score * (1 - ($priorityIndex * 0.15)),
        ];
    }

    /**
     * @internal Used by tests to simulate missing PDO drivers
     */
    protected function getAvailablePdoDrivers(): array
    {
        return PDO::getAvailableDrivers();
    }

    /**
     * Check if the Redis client that RedisEngine uses is available.
     * tntsearch requires it at the moment, but that could change in a future version.
     *
     * @internal Used by tests to simulate a missing Redis client
     */
    protected function hasPredisClient(): bool
    {
        return class_exists(PredisClient::class);
    }

    private function getTntSearch(): TNTSearch
    {
        if (!$this->tntSearch) {
            $this->tntSearch = Injector::inst()->get(TNTSearch::class . '.userdocs');
            $this->tntSearch->setFuzzyNoLimit(static::config()->get('disable_search_limit'));
        }
        return $this->tntSearch;
    }
}
