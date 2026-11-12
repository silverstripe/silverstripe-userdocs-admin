<?php

namespace SilverStripe\UserDocs\Tests\Search;

use BadMethodCallException;
use Error;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Path;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Search\SearchForm;
use SilverStripe\UserDocs\Search\SearchIndex;
use SilverStripe\UserDocs\Search\TntSearchFactory;
use SilverStripe\UserDocs\Tests\Search\SearchIndexTest\StubDependenciesSearchIndex;
use SilverStripe\UserDocs\Tests\Search\SearchIndexTest\StubEngine;
use SilverStripe\UserDocs\Tests\Search\SearchIndexTest\StubRedisEngine;
use SilverStripe\UserDocs\Tests\Search\SearchIndexTest\StubUserDocsManifest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Filesystem\Filesystem;
use TeamTNT\TNTSearch\Engines\MysqlEngine;
use TeamTNT\TNTSearch\Engines\SqliteEngine;
use TeamTNT\TNTSearch\Stemmer\PorterStemmer;
use TeamTNT\TNTSearch\TNTSearch;

class SearchIndexTest extends SapphireTest
{
    /**
     * IDs of the pages in StubUserDocsManifest, which are their positions in the sorted slugs
     */
    private const array PAGE_IDS = [
        'formatting' => 1,
        'loops' => 2,
        'other' => 3,
        'template_syntax' => 4,
    ];

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = Path::join(TEMP_PATH, 'userdocs-search-index-test');
        Injector::inst()->registerService(
            new Psr16Cache(new ArrayAdapter()),
            CacheInterface::class . '.userdocssearch'
        );
        Injector::inst()->registerService(
            (new TntSearchFactory())->create(TNTSearch::class, [
                'config' => ['driver' => 'filesystem', 'storage' => $this->storagePath],
            ]),
            TNTSearch::class . '.userdocs'
        );
        Injector::inst()->registerService(new StubUserDocsManifest(), UserDocsManifest::class);
        i18n::config()->set('default_locale', 'en_US');
        i18n::set_locale('en_US');
        // Search for exact words unless a test is about stemming
        SearchIndex::config()->set('stemmers', []);
        SearchIndex::config()->set('stemmer_aliases', []);
    }

    /**
     * Register a TNTSearch service that uses the given engine.
     * The service is created lazily, so that errors from loading the engine config happen in isSupported().
     */
    private function registerTntSearchWithEngine(string $engine): void
    {
        // Remove the instance from setUp(), otherwise load() creates the new service immediately
        Injector::inst()->unregisterNamedObject(TNTSearch::class . '.userdocs');
        Injector::inst()->load([
            TNTSearch::class . '.userdocs' => [
                'factory' => TntSearchFactory::class,
                'constructor' => [
                    'config' => [
                        'driver' => 'filesystem',
                        'storage' => $this->storagePath,
                        'engine' => $engine,
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->storagePath);
        StubRedisEngine::$loadConfigError = null;
        parent::tearDown();
    }

    public static function provideIsSupported(): array
    {
        return [
            'sqlite engine with sqlite driver' => [
                'engine' => SqliteEngine::class,
                'pdoDrivers' => ['sqlite'],
                'hasPredisClient' => false,
                'expected' => true,
            ],
            'sqlite engine without sqlite driver' => [
                'engine' => SqliteEngine::class,
                'pdoDrivers' => ['mysql'],
                'hasPredisClient' => true,
                'expected' => false,
            ],
            'mysql engine with mysql driver' => [
                'engine' => MysqlEngine::class,
                'pdoDrivers' => ['mysql'],
                'hasPredisClient' => false,
                'expected' => true,
            ],
            // MysqlEngine is a subclass of SqliteEngine
            'mysql engine with only sqlite driver' => [
                'engine' => MysqlEngine::class,
                'pdoDrivers' => ['sqlite'],
                'hasPredisClient' => true,
                'expected' => false,
            ],
            'redis engine with predis client' => [
                'engine' => StubRedisEngine::class,
                'pdoDrivers' => [],
                'hasPredisClient' => true,
                'expected' => true,
            ],
            'redis engine without predis client' => [
                'engine' => StubRedisEngine::class,
                'pdoDrivers' => ['mysql', 'sqlite'],
                'hasPredisClient' => false,
                'expected' => false,
            ],
            'other engine without dependencies' => [
                'engine' => StubEngine::class,
                'pdoDrivers' => [],
                'hasPredisClient' => false,
                'expected' => true,
            ],
        ];
    }

    #[DataProvider('provideIsSupported')]
    public function testIsSupported(string $engine, array $pdoDrivers, bool $hasPredisClient, bool $expected): void
    {
        $this->registerTntSearchWithEngine($engine);
        $index = new StubDependenciesSearchIndex($pdoDrivers, $hasPredisClient);

        $this->assertSame($expected, $index->isSupported());
    }

    public function testIsSupportedRedisEngineCannotLoadPredisClient(): void
    {
        StubRedisEngine::$loadConfigError = new Error('Class "Predis\\Client" not found');
        $this->registerTntSearchWithEngine(StubRedisEngine::class);
        $index = new StubDependenciesSearchIndex([], false);

        $this->assertFalse($index->isSupported());
    }

    public static function provideIsSupportedRethrowsError(): array
    {
        return [
            'other error without predis client' => [
                'message' => 'Something else went wrong',
                'hasPredisClient' => false,
            ],
            'missing predis error with predis client' => [
                'message' => 'Class "Predis\\Client" not found',
                'hasPredisClient' => true,
            ],
        ];
    }

    #[DataProvider('provideIsSupportedRethrowsError')]
    public function testIsSupportedRethrowsError(string $message, bool $hasPredisClient): void
    {
        StubRedisEngine::$loadConfigError = new Error($message);
        $this->registerTntSearchWithEngine(StubRedisEngine::class);
        $index = new StubDependenciesSearchIndex([], $hasPredisClient);

        $this->expectException(Error::class);
        $this->expectExceptionMessage($message);
        $index->isSupported();
    }

    public static function provideSearchZoneWeights(): array
    {
        return [
            'no weights' => [
                'zoneWeights' => ['title' => null, 'h1' => null, 'h2' => null],
                'expected' => ['loops', 'template_syntax'],
            ],
            'title weight' => [
                'zoneWeights' => ['title' => 5, 'h1' => null, 'h2' => null],
                'expected' => ['template_syntax', 'loops'],
            ],
            'h1 weight' => [
                'zoneWeights' => ['title' => null, 'h1' => 5, 'h2' => null],
                'expected' => ['template_syntax', 'loops'],
            ],
            // The match is in the first of several h2 headings
            'h2 weight' => [
                'zoneWeights' => ['title' => null, 'h1' => null, 'h2' => 5],
                'expected' => ['template_syntax', 'loops'],
            ],
        ];
    }

    #[DataProvider('provideSearchZoneWeights')]
    public function testSearchZoneWeights(array $zoneWeights, array $expected): void
    {
        SearchIndex::config()->set('zone_weights', $zoneWeights);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax']);

        $this->assertSame($expected, array_column($results, 'slug'));
    }

    public function testInitZoneIndexes(): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => 5, 'h1' => null]);
        SearchIndex::create()->init();

        $this->assertFileExists(Path::join($this->storagePath, 'en.index'));
        $this->assertFileExists(Path::join($this->storagePath, 'en.title.index'));
        $this->assertFileDoesNotExist(Path::join($this->storagePath, 'en.h1.index'));
    }

    public function testSearchIgnoresUnindexedZones(): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();
        // A zone weighted after indexing has no index yet
        SearchIndex::config()->set('zone_weights', ['title' => 5, 'h1' => null, 'h2' => null]);

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax']);

        $this->assertSame(['loops', 'template_syntax'], array_column($results, 'slug'));
    }

    public function testSearchUsesZonesRemovedFromConfig(): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => 5]);
        $index = SearchIndex::create();
        $index->init();
        SearchIndex::config()->set('zone_weights', ['title' => 1]);
        $expected = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'miscellaneous']);
        // The title is not in the body text index, so the title index must still be used with body text weight
        SearchIndex::config()->set('zone_weights', ['title' => null]);

        $this->assertSame($expected, $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'miscellaneous']));
        $this->assertSame(['other'], array_column($expected, 'slug'));
    }

    public function testIsBuilt(): void
    {
        $index = SearchIndex::create();
        $this->assertFalse($index->isBuilt());

        $index->init();
        $this->assertTrue($index->isBuilt());

        // fr_FR and fr come before the default locales, and haven't been checked yet
        i18n::set_locale('fr_FR');
        $this->assertFalse($index->isBuilt());
    }

    public static function provideInitIndexMissing(): array
    {
        return [
            // en_US has no content, so it is still checked after the en index is removed from the index map
            'other checked locales are left' => [
                'locale' => 'en_US',
            ],
            'no checked locales are left' => [
                'locale' => 'en',
            ],
        ];
    }

    #[DataProvider('provideInitIndexMissing')]
    public function testInitIndexMissing(string $locale): void
    {
        i18n::config()->set('default_locale', $locale);
        i18n::set_locale($locale);
        SearchIndex::create()->init();
        // Simulate another server that shares the index map cache but has its own index storage
        (new Filesystem())->remove($this->storagePath);
        $index = SearchIndex::create();
        $this->assertFalse($index->isBuilt());

        $index->init();

        $this->assertTrue($index->isBuilt());
        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax']);
        $this->assertEqualsCanonicalizing(['loops', 'template_syntax'], array_column($results, 'slug'));
    }

    public function testSearchIndexNotBuilt(): void
    {
        $index = SearchIndex::create();

        $this->expectException(BadMethodCallException::class);
        $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax']);
    }

    public function testSearchIndexNotBuiltForLocale(): void
    {
        $index = SearchIndex::create();
        $index->init();
        // fr_FR and fr come before the default locales, and haven't been checked yet
        i18n::set_locale('fr_FR');

        $this->expectException(BadMethodCallException::class);
        $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax']);
    }

    public function testSearchLocaleWithoutContent(): void
    {
        // There are no docs for fr_FR or fr, so they have no indexes but have still been checked
        i18n::set_locale('fr_FR');
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax']);

        $this->assertSame(['loops', 'template_syntax'], array_column($results, 'slug'));
    }

    public static function provideSearchLimit(): array
    {
        return [
            'limit disabled' => [
                'disableSearchLimit' => true,
                'expected' => ['loops', 'template_syntax'],
            ],
            'limit enabled' => [
                'disableSearchLimit' => false,
                'expected' => ['loops'],
            ],
        ];
    }

    #[DataProvider('provideSearchLimit')]
    public function testSearchLimit(bool $disableSearchLimit, array $expected): void
    {
        SearchIndex::config()->set('disable_search_limit', $disableSearchLimit);
        SearchIndex::config()->set('zone_weights', []);
        // With the limit, each word finds only one page
        Injector::inst()->get(TNTSearch::class . '.userdocs')->engine->maxDocs = 1;
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'template']);

        $this->assertEqualsCanonicalizing($expected, array_column($results, 'slug'));
    }

    public static function provideGetQueryRecommendation(): array
    {
        return [
            'misspelled body text word' => [
                'locale' => 'en_US',
                'query' => 'repat',
                'expected' => 'repeat',
            ],
            // "Miscellaneous" is only in a title, so it's only in the title zone index
            'misspelled zone word' => [
                'locale' => 'en_US',
                'query' => 'miscelaneous',
                'expected' => 'miscellaneous',
            ],
            'only one word misspelled' => [
                'locale' => 'en_US',
                'query' => 'loops repat',
                'expected' => 'loops repeat',
            ],
            'case and punctuation' => [
                'locale' => 'en_US',
                'query' => 'Repat!',
                'expected' => 'repeat',
            ],
            'all words indexed' => [
                'locale' => 'en_US',
                'query' => 'loops',
                'expected' => null,
            ],
            'no close match' => [
                'locale' => 'en_US',
                'query' => 'xyzzy',
                'expected' => null,
            ],
            // There are no French docs, so the English indexes are used
            'fallback locale' => [
                'locale' => 'fr_FR',
                'query' => 'repat',
                'expected' => 'repeat',
            ],
        ];
    }

    #[DataProvider('provideGetQueryRecommendation')]
    public function testGetQueryRecommendation(string $locale, string $query, ?string $expected): void
    {
        i18n::set_locale($locale);
        SearchIndex::config()->set('zone_weights', ['title' => 5, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();

        $this->assertSame($expected, $index->getQueryRecommendation($query));
    }

    public function testGetQueryRecommendationIndexNotBuilt(): void
    {
        $index = SearchIndex::create();

        $this->expectException(BadMethodCallException::class);
        $index->getQueryRecommendation('repat');
    }

    public static function provideGetQueryRecommendationStopWords(): array
    {
        return [
            'stop word is kept' => [
                'query' => 'the repat',
                'expected' => 'the repeat',
            ],
            'only stop words' => [
                'query' => 'The',
                'expected' => null,
            ],
        ];
    }

    #[DataProvider('provideGetQueryRecommendationStopWords')]
    public function testGetQueryRecommendationStopWords(string $query, ?string $expected): void
    {
        // Without stop words, "the" would be replaced with a close match such as "then"
        SearchIndex::config()->set('stop_words', ['en' => ['the']]);
        $index = SearchIndex::create();
        $index->init();

        $this->assertSame($expected, $index->getQueryRecommendation($query));
    }

    public function testGetQueryRecommendationStemming(): void
    {
        SearchIndex::config()->set('stemmers', ['en' => PorterStemmer::class]);
        $index = SearchIndex::create();
        $index->init();

        // Without stemming, "repeats" would be replaced with "repeat"
        $this->assertNull($index->getQueryRecommendation('repeats'));
    }

    public function testSearchFallbackLocale(): void
    {
        // There are no French docs, so results come from the English indexes
        i18n::set_locale('fr_FR');
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();

        $result = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'xylophone'])[0];

        $this->assertSame('en', $result['matched_locale']);
        $this->assertTrue($result['is_fallback']);
    }

    public static function provideSearchTitleFilter(): array
    {
        return [
            'terms only' => [
                'query' => [SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax'],
                'expected' => ['template_syntax', 'loops'],
            ],
            'title only' => [
                'query' => ['title' => 'loops'],
                'expected' => ['loops'],
            ],
            // "Miscellaneous" is only in the title, not in the body text
            'title only with no body text match' => [
                'query' => ['title' => 'miscellaneous'],
                'expected' => ['other'],
            ],
            'title and terms' => [
                'query' => [SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax', 'title' => 'loops'],
                'expected' => ['loops'],
            ],
            'title matches no term results' => [
                'query' => [SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax', 'title' => 'formatting'],
                'expected' => [],
            ],
            'empty query' => [
                'query' => [SearchForm::DEFAULT_SEARCH_FIELD => '', 'title' => ''],
                'expected' => [],
            ],
            'whitespace only query' => [
                'query' => [SearchForm::DEFAULT_SEARCH_FIELD => ' ', 'title' => ' '],
                'expected' => [],
            ],
        ];
    }

    #[DataProvider('provideSearchTitleFilter')]
    public function testSearchTitleFilter(array $query, array $expected): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => 5, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();

        $this->assertSame($expected, array_column($index->search($query), 'slug'));
    }

    public static function provideSearchNonStringValues(): array
    {
        return [
            'terms are not a string' => [
                'query' => [SearchForm::DEFAULT_SEARCH_FIELD => ['template syntax']],
                'expected' => [],
            ],
            'title is not a string' => [
                'query' => [SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax', 'title' => ['loops']],
                'expected' => ['template_syntax', 'loops'],
            ],
        ];
    }

    #[DataProvider('provideSearchNonStringValues')]
    public function testSearchNonStringValues(array $query, array $expected): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => 5, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();

        $this->assertSame($expected, array_column($index->search($query), 'slug'));
    }

    public static function provideSearchTitleFilterWithoutTitleIndex(): array
    {
        return [
            'title only' => [
                'query' => ['title' => 'miscellaneous'],
                'expected' => ['other'],
            ],
            'words in any order and case' => [
                'query' => ['title' => 'SYNTAX Template'],
                'expected' => ['template_syntax'],
            ],
            'any word matches, more matched words rank higher' => [
                'query' => ['title' => 'loops template syntax'],
                'expected' => ['template_syntax', 'loops'],
            ],
            'title and terms' => [
                'query' => [SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax', 'title' => 'loops'],
                'expected' => ['loops'],
            ],
        ];
    }

    #[DataProvider('provideSearchTitleFilterWithoutTitleIndex')]
    public function testSearchTitleFilterWithoutTitleIndex(array $query, array $expected): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();

        $this->assertSame($expected, array_column($index->search($query), 'slug'));
    }

    public function testSearchTitleFilterWithoutTitleIndexStopWords(): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        SearchIndex::config()->set('stop_words', ['en' => ['syntax']]);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search(['title' => 'loops syntax']);

        // "Template syntax" would also match if "syntax" was not a stop word
        $this->assertSame(['loops'], array_column($results, 'slug'));
    }

    public function testSearchTitleFilterWithoutTitleIndexStemming(): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        SearchIndex::config()->set('stemmers', ['en' => PorterStemmer::class]);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search(['title' => 'loop']);

        // The title is "Loops"
        $this->assertSame(['loops'], array_column($results, 'slug'));
    }

    public static function provideSearchStopWords(): array
    {
        return [
            'no stop words' => [
                'stopWords' => [],
                'expected' => ['formatting', 'formatting_us'],
            ],
            'locale uses its own stop words' => [
                'stopWords' => ['en_US' => ['xylophone']],
                'expected' => ['formatting'],
            ],
            'locale falls back to primary language' => [
                'stopWords' => ['en' => ['xylophone']],
                'expected' => [],
            ],
            'empty list for locale overrides primary language' => [
                'stopWords' => ['en' => ['xylophone'], 'en_US' => []],
                'expected' => ['formatting_us'],
            ],
        ];
    }

    #[DataProvider('provideSearchStopWords')]
    public function testSearchStopWords(array $stopWords, array $expected): void
    {
        Injector::inst()->registerService(new StubUserDocsManifest([
            'en_US' => [
                'formatting_us' => [
                    'title' => 'Formatting',
                    'hasContent' => true,
                    'filePath' => Path::join(__DIR__, 'SearchIndexTest', 'formatting.md'),
                    'slug' => 'formatting_us',
                ],
            ],
        ]), UserDocsManifest::class);
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        SearchIndex::config()->set('stop_words', $stopWords);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'xylophone']);

        $this->assertEqualsCanonicalizing($expected, array_column($results, 'slug'));
    }

    public static function provideSearchStemming(): array
    {
        return [
            // The docs only have "repeat"
            'other word form' => [
                'stemmers' => ['en' => PorterStemmer::class],
                'stemmerAliases' => [],
                'query' => 'repeats',
                'expected' => ['loops'],
            ],
            // The stem of "repetition" is "repetit"
            'stemmer alias' => [
                'stemmers' => ['en' => PorterStemmer::class],
                'stemmerAliases' => ['en' => ['repetit' => 'repeat']],
                'query' => 'repetition',
                'expected' => ['loops'],
            ],
            'no stemmer for locale' => [
                'stemmers' => ['fr' => PorterStemmer::class],
                'stemmerAliases' => [],
                'query' => 'repeats',
                'expected' => [],
            ],
        ];
    }

    #[DataProvider('provideSearchStemming')]
    public function testSearchStemming(array $stemmers, array $stemmerAliases, string $query, array $expected): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        SearchIndex::config()->set('stemmers', $stemmers);
        SearchIndex::config()->set('stemmer_aliases', $stemmerAliases);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => $query]);

        $this->assertSame($expected, array_column($results, 'slug'));
    }

    public function testSearchStemmingNewInstance(): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        SearchIndex::config()->set('stemmers', ['en' => PorterStemmer::class]);
        SearchIndex::create()->init();

        // The stem variants come from the cache, because this instance didn't build the indexes
        $results = SearchIndex::create()->search([SearchForm::DEFAULT_SEARCH_FIELD => 'repeats']);

        $this->assertSame(['loops'], array_column($results, 'slug'));
    }

    public static function provideSearchBodyText(): array
    {
        return [
            'line break' => [
                'query' => 'copy',
                'expected' => ['formatting'],
            ],
            'block boundary' => [
                'query' => 'paste',
                'expected' => ['formatting'],
            ],
            'text in raw HTML' => [
                'query' => 'ctrl',
                'expected' => ['formatting'],
            ],
            'raw HTML tag' => [
                'query' => 'kbd',
                'expected' => [],
            ],
        ];
    }

    #[DataProvider('provideSearchBodyText')]
    public function testSearchBodyText(string $query, array $expected): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => $query]);

        $this->assertSame($expected, array_column($results, 'slug'));
    }

    public function testSearchExcludesTableOfContents(): void
    {
        // A heading match is worth much less than body text, so the page with "shortcuts" in its body
        // ranks first unless the table of contents adds the h2 text to the body text.
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => 0.01]);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'shortcuts']);

        $this->assertSame(['other', 'formatting'], array_column($results, 'slug'));
    }

    public static function provideSearchExcludedNodeClasses(): array
    {
        return [
            'not excluded' => [
                'excludedNodeClasses' => [],
                'expected' => ['formatting'],
            ],
            'excluded' => [
                'excludedNodeClasses' => [FencedCode::class],
                'expected' => [],
            ],
        ];
    }

    #[DataProvider('provideSearchExcludedNodeClasses')]
    public function testSearchExcludedNodeClasses(array $excludedNodeClasses, array $expected): void
    {
        SearchIndex::config()->set('excluded_node_classes', $excludedNodeClasses);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'xylophone']);

        $this->assertSame($expected, array_column($results, 'slug'));
    }

    public static function provideSearchLocaleShadowing(): array
    {
        return [
            'shadowed by locale with content' => [
                'hasContent' => true,
                'fileExists' => true,
                'expected' => ['template_syntax'],
            ],
            'not shadowed by locale without content' => [
                'hasContent' => false,
                'fileExists' => true,
                'expected' => ['loops', 'template_syntax'],
            ],
            'not shadowed by locale with missing file' => [
                'hasContent' => true,
                'fileExists' => false,
                'expected' => ['loops', 'template_syntax'],
            ],
        ];
    }

    #[DataProvider('provideSearchLocaleShadowing')]
    public function testSearchLocaleShadowing(bool $hasContent, bool $fileExists, array $expected): void
    {
        $filePath = Path::join($this->storagePath, 'fr_loops.md');
        if ($fileExists) {
            (new Filesystem())->dumpFile($filePath, 'Boucles');
        }
        Injector::inst()->registerService(new StubUserDocsManifest([
            'fr' => [
                'loops' => [
                    'title' => 'Boucles',
                    'hasContent' => $hasContent,
                    'filePath' => $filePath,
                    'slug' => 'loops',
                ],
            ],
        ]), UserDocsManifest::class);
        i18n::set_locale('fr_FR');
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax']);

        $this->assertSame($expected, array_column($results, 'slug'));
    }

    public function testSearchLocaleShadowingByLowerPriorityModule(): void
    {
        $frFilePath = Path::join($this->storagePath, 'fr_loops.md');
        (new Filesystem())->dumpFile($frFilePath, 'Boucles');
        $enLoops = [
            'title' => 'Loops',
            'hasContent' => true,
            'filePath' => Path::join(__DIR__, 'SearchIndexTest', 'loops.md'),
            'slug' => 'loops',
            'module' => 'test/module-a',
        ];
        // The French page is from a module with a lower priority, so the manifest uses the English page
        Injector::inst()->registerService(new StubUserDocsManifest(
            [
                'en' => ['loops' => $enLoops],
                'fr' => [
                    'loops' => [
                        'title' => 'Boucles',
                        'hasContent' => true,
                        'filePath' => $frFilePath,
                        'slug' => 'loops',
                        'module' => 'test/module-b',
                    ],
                ],
            ],
            ['loops' => $enLoops]
        ), UserDocsManifest::class);
        i18n::set_locale('fr_FR');
        SearchIndex::config()->set('zone_weights', ['title' => null, 'h1' => null, 'h2' => null]);
        $index = SearchIndex::create();
        $index->init();

        $results = $index->search([SearchForm::DEFAULT_SEARCH_FIELD => 'template syntax']);

        $this->assertSame(['loops', 'template_syntax'], array_column($results, 'slug'));
    }

    public static function provideCreateSummary(): array
    {
        return [
            'highlighted' => [
                'highlight' => true,
                'expected' => 'Template syntax Syntax rules Rules for writing templates. '
                    . '<em>Variables</em> Use <em>variables</em> to render data.',
            ],
            'not highlighted' => [
                'highlight' => false,
                'expected' => 'Template syntax Syntax rules Rules for writing templates. '
                    . 'Variables Use variables to render data.',
            ],
        ];
    }

    #[DataProvider('provideCreateSummary')]
    public function testCreateSummary(bool $highlight, string $expected): void
    {
        $index = SearchIndex::create();
        $index->init();

        $summary = $index->createSummary('variables', static::PAGE_IDS['template_syntax'], $highlight);

        $this->assertSame($expected, $summary);
    }

    public function testCreateSummaryStopWords(): void
    {
        SearchIndex::config()->set('stop_words', ['en' => ['for']]);
        $index = SearchIndex::create();
        $index->init();

        $summary = $index->createSummary('for variables', static::PAGE_IDS['template_syntax']);

        $this->assertSame(
            'Template syntax Syntax rules Rules for writing templates. '
                . '<em>Variables</em> Use <em>variables</em> to render data.',
            $summary
        );
    }

    public function testCreateSummaryStemming(): void
    {
        SearchIndex::config()->set('stemmers', ['en' => PorterStemmer::class]);
        $index = SearchIndex::create();
        $index->init();

        $summary = $index->createSummary('variable', static::PAGE_IDS['template_syntax']);

        $this->assertSame(
            'Template syntax Syntax rules Rules for writing templates. '
                . '<em>Variables</em> Use <em>variables</em> to render data.',
            $summary
        );
    }

    public function testCreateSummaryEscapesHtml(): void
    {
        $index = SearchIndex::create();
        $index->init();

        $summary = $index->createSummary('xylophone', static::PAGE_IDS['formatting']);

        $this->assertStringContainsString('&lt;% <em>xylophone</em> %&gt;', $summary);
    }

    public function testCreateSummaryUnknownPage(): void
    {
        $index = SearchIndex::create();
        $index->init();

        $this->assertSame('', $index->createSummary('variables', '123'));
    }

    public function testCreateSummaryMissingFile(): void
    {
        $filePath = Path::join($this->storagePath, 'deleted.md');
        (new Filesystem())->dumpFile($filePath, 'Variables');
        Injector::inst()->registerService(new StubUserDocsManifest([
            'en' => [
                'deleted' => [
                    'title' => 'Deleted',
                    'hasContent' => true,
                    'filePath' => $filePath,
                    'slug' => 'deleted',
                ],
            ],
        ]), UserDocsManifest::class);
        $index = SearchIndex::create();
        $index->init();
        unlink($filePath);

        // "deleted" is the first of the sorted slugs
        $this->assertSame('', $index->createSummary('variables', 1));
    }

    public function testClearIndexRemovesZoneIndexes(): void
    {
        SearchIndex::config()->set('zone_weights', ['title' => 5]);
        $index = SearchIndex::create();
        $index->init();
        // A zone removed from the config after indexing still has an index to clear
        SearchIndex::config()->set('zone_weights', ['title' => null]);

        $index->clearIndex();

        $this->assertFileDoesNotExist(Path::join($this->storagePath, 'en.index'));
        $this->assertFileDoesNotExist(Path::join($this->storagePath, 'en.title.index'));
    }
}
