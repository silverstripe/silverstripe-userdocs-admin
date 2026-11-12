<?php

namespace SilverStripe\UserDocs\Tests\Admin;

use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Security;
use SilverStripe\UserDocs\Admin\UserDocsAdmin;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Search\SearchIndex;
use SilverStripe\UserDocs\Search\TntSearchFactory;
use SilverStripe\UserDocs\Tests\Admin\UserDocsAdminTest\TestUserDocsManifest;
use SilverStripe\UserDocs\Tests\ExtensionModulesTrait;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use TeamTNT\TNTSearch\TNTSearch;

class UserDocsAdminTest extends FunctionalTest
{
    use ExtensionModulesTrait;

    protected $usesDatabase = true;

    private string $searchStoragePath;

    /**
     * Temporary base folder whose public folder holds exposed images, if a test needs one
     */
    private ?string $tempBaseFolder = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the real site's manifest cache out of it
        Injector::inst()->registerService(
            new Psr16Cache(new ArrayAdapter()),
            CacheInterface::class . '.userdocsmanifest'
        );
        // Keep the real site's search index out of it
        $this->searchStoragePath = Path::join(TEMP_PATH, 'userdocs-admin-test-search-index');
        Injector::inst()->registerService(
            new Psr16Cache(new ArrayAdapter()),
            CacheInterface::class . '.userdocssearch'
        );
        Injector::inst()->registerService(
            (new TntSearchFactory())->create(TNTSearch::class, [
                'config' => ['driver' => 'filesystem', 'storage' => $this->searchStoragePath],
            ]),
            TNTSearch::class . '.userdocs'
        );
        // A singleton created before the test can have state from other tests
        Injector::inst()->registerService(new SearchIndex(), SearchIndex::class);
        UserDocsManifest::config()->set('documentation_roots', [
            'silverstripe/userdocs-admin:tests/php/fixtures/docs' => true,
        ]);
        Injector::inst()->registerService(new TestUserDocsManifest(), UserDocsManifest::class);
        $this->logInWithPermission('ADMIN');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->searchStoragePath);
        $this->popExtensionModules();
        if ($this->tempBaseFolder) {
            (new Filesystem())->remove($this->tempBaseFolder);
            $this->tempBaseFolder = null;
        }
        parent::tearDown();
    }

    public function testGetSearchResultsBuildsIndex(): void
    {
        $response = $this->get('admin/user-docs?q[terms]=details');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(SearchIndex::singleton()->isBuilt());
    }

    public static function provideGetSearchResultsInvalidQuery(): array
    {
        return [
            // The page isn't filtered, but the search form still reads the query
            'query is not an array' => [
                'queryString' => 'q=details',
            ],
            // The title filters the page, so the search runs with the invalid terms
            'terms are not a string' => [
                'queryString' => 'q[terms][]=details&q[title]=details',
            ],
            // The title matches a page, so the result summary is rendered with the invalid terms
            'terms are not a string with title match' => [
                'queryString' => 'q[terms][]=details&q[title]=first',
            ],
        ];
    }

    #[DataProvider('provideGetSearchResultsInvalidQuery')]
    public function testGetSearchResultsInvalidQuery(string $queryString): void
    {
        $response = $this->get("admin/user-docs?$queryString");

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDocs(): void
    {
        $response = $this->get('admin/user-docs/docs/getting_started/first_page');

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('<h1 id="userdocs-first-page">First page', $body);
        // Links are rewritten to point at other docs pages within the admin
        $this->assertStringContainsString(
            '<a href="admin/user-docs/docs/getting_started/second_page">second page</a>',
            $body
        );
    }

    public function testDocsWithoutCmsAccess(): void
    {
        $this->logInWithPermission('SOME_OTHER_PERMISSION');

        $response = $this->get('admin/user-docs/docs/getting_started/first_page');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('<h1 id="userdocs-first-page">First page', $response->getBody());
    }

    public function testDocsSearchFormIds(): void
    {
        $response = $this->get('admin/user-docs/docs/getting_started/first_page');

        $body = $response->getBody();
        // The left panel and the short-width header each have a search button and panel with their own ID
        foreach (['UserDocsAdmin_SearchForm', 'UserDocsAdmin_SearchForm_ShortWidth'] as $id) {
            $this->assertSame(1, substr_count($body, 'id="' . $id . '"'));
            $this->assertSame(1, substr_count($body, 'aria-controls="' . $id . '"'));
        }
    }

    public static function provideDocsMissingDoc(): array
    {
        return [
            'unknown slug' => [
                'url' => 'admin/user-docs/docs/getting_started/does_not_exist',
            ],
            'page without content' => [
                'url' => 'admin/user-docs/docs/title_only',
            ],
        ];
    }

    #[DataProvider('provideDocsMissingDoc')]
    public function testDocsMissingDoc(string $url): void
    {
        $response = $this->get($url);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRenderDoc(): void
    {
        UserDocsManifest::singleton()->init();
        $admin = new UserDocsAdmin();

        // Rendering more than once in a request must work, e.g. for a JSON endpoint
        $firstPage = $admin->renderDoc('getting_started/first_page');
        $secondPage = $admin->renderDoc('getting_started/second_page');

        $this->assertStringContainsString('<h1 id="userdocs-first-page">First page', $firstPage);
        $this->assertStringContainsString('<h1 id="userdocs-second-page">Second page', $secondPage);
    }

    public function testRenderDocHeadingPermalink(): void
    {
        UserDocsManifest::singleton()->init();

        $html = (new UserDocsAdmin())->renderDoc('getting_started/first_page');

        $this->assertMatchesRegularExpression('#<a\b[^>]*\bclass="heading-permalink"[^>]*>#', $html);
        $this->assertDoesNotMatchRegularExpression('#<a\b[^>]*\bclass="heading-permalink"[^>]*\btabindex=#', $html);
        $this->assertDoesNotMatchRegularExpression('#<a\b[^>]*\bclass="heading-permalink"[^>]*\baria-hidden=#', $html);
    }

    public function testRenderDocWithUnsafeLink(): void
    {
        UserDocsManifest::config()->set('documentation_roots', [
            'silverstripe/userdocs-admin:tests/php/fixtures/unsafe_links' => true,
        ]);
        UserDocsManifest::singleton()->init();

        $html = (new UserDocsAdmin())->renderDoc('section/page');

        $this->assertStringContainsString('>Click me</a>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public static function provideRenderDocMissingDoc(): array
    {
        return [
            'unknown slug' => [
                'slug' => 'getting_started/does_not_exist',
            ],
            'page without content' => [
                'slug' => 'title_only',
            ],
        ];
    }

    #[DataProvider('provideRenderDocMissingDoc')]
    public function testRenderDocMissingDoc(string $slug): void
    {
        UserDocsManifest::singleton()->init();

        $this->assertNull((new UserDocsAdmin())->renderDoc($slug));
    }

    public function testRenderDocWithExtension(): void
    {
        $this->useExtensionModules(['one', 'two']);
        $imagePath = 'test/module-two:docs/en/01_section/images/extension.png';
        $this->exposeResource($imagePath);

        $html = (new UserDocsAdmin())->renderDoc('section/page');

        // Extension content is added after the original content
        $originalPosition = strpos($html, 'Original content');
        $this->assertNotFalse($originalPosition);
        $this->assertGreaterThan($originalPosition, strpos($html, 'Module two content'));
        // Markers and the extension's frontmatter aren't rendered
        $this->assertStringNotContainsString('UserDocsAdmin__userdocs-extension', $html);
        $this->assertStringNotContainsString('extends: true', $html);
        $this->assertStringNotContainsString('<hr', $html);
        // Images in the extension are relative to the extension's file
        $this->assertStringContainsString('src="' . ModuleResourceLoader::resourceURL($imagePath), $html);
    }

    public function testRenderDocWithExtensionHeadings(): void
    {
        $this->useExtensionModules(['one', 'two']);

        $html = (new UserDocsAdmin())->renderDoc('section/page');

        // There is one table of contents
        $this->assertSame(1, substr_count($html, 'class="toc-container"'));
        preg_match('#<nav class="toc-container".*?</nav>#s', $html, $tableOfContents);
        // The original and the extension both have a "Notes" heading, but each needs its own ID
        $contentHtml = str_replace($tableOfContents[0], '', $html);
        preg_match_all('#<h2 id="([^"]+)"#', $contentHtml, $matches);
        $headingIds = $matches[1];
        $this->assertCount(2, $headingIds);
        $this->assertSame($headingIds, array_values(array_unique($headingIds)));
        // The table of contents includes the headings from the extension
        foreach ($headingIds as $headingId) {
            $this->assertStringContainsString("#$headingId\"", $tableOfContents[0]);
        }
    }

    public function testRenderDocWithExtensionPoint(): void
    {
        $this->useExtensionModules(['one', 'two']);

        $html = (new UserDocsAdmin())->renderDoc('section/extension_point');

        // The extension's content goes at the extension point, and its heading is in the table of contents
        $beforePosition = strpos($html, 'Before extension point.');
        $insertedPosition = strpos($html, 'Inserted content.');
        $this->assertNotFalse($beforePosition);
        $this->assertGreaterThan($beforePosition, $insertedPosition);
        $this->assertGreaterThan($insertedPosition, strpos($html, 'After extension point.'));
        preg_match('#<nav class="toc-container".*?</nav>#s', $html, $tableOfContents);
        $this->assertStringContainsString('#userdocs-inserted-heading"', $tableOfContents[0] ?? '');
        // Markers aren't rendered
        $this->assertStringNotContainsString('<!--', $html);
    }

    public function testRenderDocWithHeadingInsert(): void
    {
        $this->useExtensionModules(['one', 'two']);

        $html = (new UserDocsAdmin())->renderDoc('section/extension_point');

        // The heading is found by its ID without the prefix, and the extension's content goes directly after it
        $headingPosition = strpos($html, '<h2 id="userdocs-original-heading"');
        $insertedPosition = strpos($html, 'Inserted after heading.');
        $this->assertNotFalse($headingPosition);
        $this->assertGreaterThan($headingPosition, $insertedPosition);
        $this->assertGreaterThan($insertedPosition, strpos($html, 'Original heading content.'));
    }

    public static function provideRenderDocWithHiddenExtensionMarker(): array
    {
        return [
            'unclosed code block in the original doc' => [
                'slug' => 'section/unclosed_code',
                'moduleNames' => ['one', 'two'],
                'hiddenModuleName' => 'two',
                'hiddenFileName' => '12_unclosed_code.md',
            ],
            // The unclosed code block is removed with the content for the missing extension point,
            // which must not stop the hidden marker from being found
            'unclosed code block in content that is removed' => [
                'slug' => 'section/unclosed_code_removed',
                'moduleNames' => ['one', 'two', 'three'],
                'hiddenModuleName' => 'two',
                'hiddenFileName' => '13_unclosed_code_removed.md',
            ],
        ];
    }

    #[DataProvider('provideRenderDocWithHiddenExtensionMarker')]
    public function testRenderDocWithHiddenExtensionMarker(
        string $slug,
        array $moduleNames,
        string $hiddenModuleName,
        string $hiddenFileName
    ): void {
        $this->useExtensionModules($moduleNames);
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message) use (&$warnings) {
            $warnings[] = $message;
        });
        Injector::inst()->registerService($logger, LoggerInterface::class);

        (new UserDocsAdmin())->renderDoc($slug);

        // The warning names the extension doc after the unclosed code block
        $extensionPath = $this->getExtensionModulePath($hiddenModuleName) . "/docs/en/01_section/$hiddenFileName";
        $this->assertSame([
            "Could not find where the content from '$extensionPath' starts, so it might be in the wrong place"
            . ' and its links and images might not work.'
            . ' Check the content before it for an unclosed code block or HTML block.',
        ], $warnings);
    }

    public static function provideRenderDocWithExtensionKey(): array
    {
        return [
            'overridden extension' => [
                'slug' => 'section/overridden',
                'expectedContent' => ['Original content.', 'Module three override.'],
                'unexpectedContent' => ['Module two extension.'],
            ],
            'removed extension' => [
                'slug' => 'section/removed',
                'expectedContent' => ['Original content.'],
                'unexpectedContent' => ['Module two removed content.'],
            ],
            'key that isn\'t a module name' => [
                'slug' => 'section/invalid_key',
                'expectedContent' => ['Original content.'],
                'unexpectedContent' => ['Invalid key content.'],
            ],
            'extension of a module that isn\'t installed' => [
                'slug' => 'section/uninstalled',
                'expectedContent' => ['Original content.'],
                'unexpectedContent' => ['Uninstalled module content.'],
            ],
        ];
    }

    #[DataProvider('provideRenderDocWithExtensionKey')]
    public function testRenderDocWithExtensionKey(string $slug, array $expectedContent, array $unexpectedContent): void
    {
        $this->useExtensionModules(['three', 'two', 'one']);

        $html = (new UserDocsAdmin())->renderDoc($slug);

        foreach ($expectedContent as $content) {
            $this->assertStringContainsString($content, $html);
        }
        foreach ($unexpectedContent as $content) {
            $this->assertStringNotContainsString($content, $html);
        }
    }

    public function testRenderDocWithFrontMatterOnlyExtension(): void
    {
        $this->useExtensionModules(['one', 'two']);

        $html = (new UserDocsAdmin())->renderDoc('section/frontmatter_only_extension');

        $this->assertSame(1, substr_count($html, 'Original content'));
    }

    public function testRenderDocWithExtensionWithoutOriginal(): void
    {
        $this->useExtensionModules(['two', 'one']);

        $html = (new UserDocsAdmin())->renderDoc('section/extension_only');

        $this->assertSame(1, substr_count($html, 'Extension only content'));
    }

    public static function provideRenderDocWithExtensionOfMissingDoc(): array
    {
        return [
            'original without content' => [
                'slug' => 'section/empty_original',
            ],
            'extension without content and without an original' => [
                'slug' => 'section/empty_extension_only',
            ],
        ];
    }

    #[DataProvider('provideRenderDocWithExtensionOfMissingDoc')]
    public function testRenderDocWithExtensionOfMissingDoc(string $slug): void
    {
        $this->useExtensionModules(['one', 'two']);

        $html = (new UserDocsAdmin())->renderDoc($slug);

        // The doc exists, but extensions don't replace a main doc that has no content
        $this->assertArrayHasKey($slug, UserDocsManifest::singleton()->getLocalisedDocData());
        $this->assertNull($html);
    }

    public function testDocsWithEncodedSlug(): void
    {
        $this->useLocalisationFixtures();

        $response = $this->get('admin/user-docs/docs/section/' . rawurlencode('été_indien'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('>Été indien<', $response->getBody());
    }

    public function testDocsWithDecomposedSlug(): void
    {
        $this->useLocalisationFixtures();

        // "e" followed by a combining acute accent, instead of the single "é" character used in slugs
        $response = $this->get('admin/user-docs/docs/section/' . rawurlencode("e\u{301}te\u{301}_indien"));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('>Été indien<', $response->getBody());
    }

    public function testDocsWithDotInSlug(): void
    {
        $this->useSpecialCharsFixtures();

        $response = $this->get('admin/user-docs/docs/section/release%2Enotes');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('>Release notes<', $response->getBody());
    }

    public function testDocsWithSpecialCharacters(): void
    {
        $this->useSpecialCharsFixtures();

        $response = $this->get('admin/user-docs/docs/section/c%23%20%26%20100%25');

        $this->assertSame(200, $response->getStatusCode());
        // The heading permalink uses the URL of the doc
        $this->assertStringContainsString(
            'href="admin/user-docs/docs/section/c%23%20%26%20100%25#userdocs-a-heading"',
            $response->getBody()
        );
    }

    public static function provideGetCurrentDocLang(): array
    {
        return [
            'translated doc' => [
                'url' => 'admin/user-docs/docs/section/first',
                'expectedLang' => 'fr',
            ],
            'doc without a translation' => [
                'url' => 'admin/user-docs/docs/section/second',
                'expectedLang' => 'en',
            ],
        ];
    }

    #[DataProvider('provideGetCurrentDocLang')]
    public function testGetCurrentDocLang(string $url, string $expectedLang): void
    {
        $this->useLocalisationFixtures();
        $member = Security::getCurrentUser();
        $member->Locale = 'fr_FR';
        $member->write();

        $response = $this->get($url);

        // The docs container has the lang attribute, whatever its other classes and attributes
        $this->assertMatchesRegularExpression(
            '#<div\b(?=[^>]*\sclass="(?:[^"]*\s)?userdocs-content[\s"])(?=[^>]*\slang="' . $expectedLang . '")[^>]*>#',
            $response->getBody()
        );
    }

    public function testTreeviewWithCurrentPage(): void
    {
        // Slashes in the slug are sent as __SLASH__
        $response = $this->get('admin/user-docs/treeview/getting_started__SLASH__nested_section__SLASH__child_page');

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertSame(1, substr_count($body, 'aria-current="page"'));
        $this->assertMatchesRegularExpression($this->openingTagPattern('a', [
            'href' => 'admin/user-docs/docs/getting_started/nested_section/child_page',
            'aria-current' => 'page',
            'tabindex' => '0',
        ]), $body);
    }

    public function testTreeviewWithoutCurrentPage(): void
    {
        $response = $this->get('admin/user-docs/treeview');

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringNotContainsString('aria-current="page"', $body);
        $this->assertSame(1, substr_count($body, 'tabindex="0"'));
        $this->assertMatchesRegularExpression($this->openingTagPattern('a', [
            'href' => 'admin/user-docs/docs/getting_started',
            'tabindex' => '0',
        ]), $body);
    }

    public function testTreeviewRootWithoutIndex(): void
    {
        $response = $this->get('admin/user-docs/treeview');

        $body = $response->getBody();
        $this->assertMatchesRegularExpression('#<strong\b[^>]*>No index</strong>#', $body);
        $this->assertDoesNotMatchRegularExpression(
            $this->openingTagPattern('a', ['href' => 'admin/user-docs/docs/no_index']),
            $body
        );
    }

    public function testTreeviewWithSpecialCharacters(): void
    {
        $this->useSpecialCharsFixtures();

        $response = $this->get('admin/user-docs/treeview');

        $this->assertMatchesRegularExpression($this->openingTagPattern('a', [
            'href' => 'admin/user-docs/docs/section/c%23%20%26%20100%25',
        ]), $response->getBody());
    }

    public function testTreeviewWithDotInSlug(): void
    {
        $this->useSpecialCharsFixtures();

        $response = $this->get('admin/user-docs/treeview/section__SLASH__release__DOT__notes');

        $this->assertMatchesRegularExpression($this->openingTagPattern('a', [
            'href' => 'admin/user-docs/docs/section/release%2Enotes',
            'aria-current' => 'page',
        ]), $response->getBody());
    }

    public function testGetSearchResultsWithSpecialCharacters(): void
    {
        $this->useSpecialCharsFixtures();

        $response = $this->get('admin/user-docs?q[terms]=special');

        $this->assertMatchesRegularExpression($this->openingTagPattern('a', [
            'class' => 'search-results__result-link',
            'href' => 'admin/user-docs/docs/section/c%23%20%26%20100%25/page',
        ]), $response->getBody());
    }

    public function testGetSearchResultsRootPageBreadcrumbs(): void
    {
        $this->useSpecialCharsFixtures();

        $response = $this->get('admin/user-docs?q[terms]=special');

        // Only the root page has no ancestors, so it has no breadcrumbs
        $body = $response->getBody();
        $this->assertSame(4, substr_count($body, 'class="search-results__result"'));
        $this->assertSame(3, substr_count($body, 'class="search-results__result-breadcrumb"'));
    }

    public function testGetDocLink(): void
    {
        $admin = UserDocsAdmin::singleton();

        $this->assertSame(
            $admin->Link('docs/' . UserDocsManifest::encodeSlug('section/c# & 100%')),
            $admin->getDocLink('section/c# & 100%')
        );
    }

    public function testGetBreadcrumbsForSlug(): void
    {
        UserDocsManifest::singleton()->init();
        $admin = UserDocsAdmin::singleton();

        $breadcrumbs = $admin->getBreadcrumbsForSlug('getting_started/first_page');

        // Links are relative to the docs action of the admin
        $this->assertSame([
            ['title' => 'Start here', 'href' => $admin->Link('docs/getting_started')],
        ], $breadcrumbs);
    }

    public static function provideGetCurrentDocSlugAsId(): array
    {
        return [
            'no slug' => [
                'currentSlug' => null,
                'slug' => null,
                'expected' => null,
            ],
            'current slug' => [
                'currentSlug' => 'getting_started/nested_section/child_page',
                'slug' => null,
                'expected' => 'getting_started__SLASH__nested_section__SLASH__child_page',
            ],
            'explicit slug overrides current slug' => [
                'currentSlug' => 'getting_started',
                'slug' => 'title_only/page',
                'expected' => 'title_only__SLASH__page',
            ],
            'slug with dots' => [
                'currentSlug' => 'section/release.notes',
                'slug' => null,
                'expected' => 'section__SLASH__release__DOT__notes',
            ],
        ];
    }

    #[DataProvider('provideGetCurrentDocSlugAsId')]
    public function testGetCurrentDocSlugAsId(?string $currentSlug, ?string $slug, ?string $expected): void
    {
        $admin = (new UserDocsAdmin())->setCurrentDocSlug($currentSlug);

        $this->assertSame($expected, $admin->getCurrentDocSlugAsId($slug));
    }

    public static function provideGetSearchQuery(): array
    {
        return [
            'whole query' => [
                'getVars' => ['q' => ['terms' => 'loops', 'title' => 'syntax']],
                'key' => null,
                'expected' => ['terms' => 'loops', 'title' => 'syntax'],
            ],
            'no query' => [
                'getVars' => [],
                'key' => null,
                'expected' => [],
            ],
            'query is not an array' => [
                'getVars' => ['q' => 'loops'],
                'key' => null,
                'expected' => [],
            ],
            'field' => [
                'getVars' => ['q' => ['terms' => 'loops', 'title' => 'syntax']],
                'key' => 'terms',
                'expected' => 'loops',
            ],
            'missing field' => [
                'getVars' => ['q' => ['title' => 'syntax']],
                'key' => 'terms',
                'expected' => '',
            ],
            'field that is an array' => [
                'getVars' => ['q' => ['terms' => ['loops']]],
                'key' => 'terms',
                'expected' => ['loops'],
            ],
            'field when query is not an array' => [
                'getVars' => ['q' => 'loops'],
                'key' => 'terms',
                'expected' => '',
            ],
        ];
    }

    #[DataProvider('provideGetSearchQuery')]
    public function testGetSearchQuery(array $getVars, ?string $key, string|array $expected): void
    {
        $admin = new UserDocsAdmin();
        $admin->setRequest(new HTTPRequest('GET', 'admin/user-docs', $getVars));

        $this->assertSame($expected, $admin->getSearchQuery($key));
    }

    public static function provideTreeIsFiltered(): array
    {
        return [
            'no query' => [
                'getVars' => [],
                'expected' => false,
            ],
            'query is not an array' => [
                'getVars' => ['q' => 'loops'],
                'expected' => false,
            ],
            'empty field' => [
                'getVars' => ['q' => ['terms' => '']],
                'expected' => false,
            ],
            'whitespace-only field' => [
                'getVars' => ['q' => ['terms' => ' ']],
                'expected' => false,
            ],
            'field that is an array' => [
                'getVars' => ['q' => ['terms' => ['loops']]],
                'expected' => false,
            ],
            'field with a value' => [
                'getVars' => ['q' => ['terms' => 'loops']],
                'expected' => true,
            ],
            'empty field and field with a value' => [
                'getVars' => ['q' => ['terms' => '', 'title' => 'syntax']],
                'expected' => true,
            ],
        ];
    }

    #[DataProvider('provideTreeIsFiltered')]
    public function testTreeIsFiltered(array $getVars, bool $expected): void
    {
        $admin = new UserDocsAdmin();
        $admin->setRequest(new HTTPRequest('GET', 'admin/user-docs', $getVars));

        $this->assertSame($expected, $admin->treeIsFiltered());
    }

    public static function provideGetSearchQueryRecommendationLink(): array
    {
        return [
            'terms' => [
                'getVars' => ['q' => ['terms' => 'lopos']],
                'expectedSuggestion' => 'loops',
            ],
            'no terms' => [
                'getVars' => ['q' => ['title' => 'syntax']],
                'expectedSuggestion' => null,
            ],
            'empty terms' => [
                'getVars' => ['q' => ['terms' => ' ']],
                'expectedSuggestion' => null,
            ],
            'terms that are an array' => [
                'getVars' => ['q' => ['terms' => ['lopos']]],
                'expectedSuggestion' => null,
            ],
            'title filter' => [
                'getVars' => ['q' => ['terms' => 'lopos', 'title' => 'syntax']],
                'expectedSuggestion' => null,
            ],
            'empty title filter' => [
                'getVars' => ['q' => ['terms' => 'lopos', 'title' => '']],
                'expectedSuggestion' => 'loops',
            ],
        ];
    }

    #[DataProvider('provideGetSearchQueryRecommendationLink')]
    public function testGetSearchQueryRecommendationLink(array $getVars, ?string $expectedSuggestion): void
    {
        $index = $this->createMock(SearchIndex::class);
        // Only valid terms are passed to the index
        $index->expects($expectedSuggestion === null ? $this->never() : $this->once())
            ->method('getQueryRecommendation')
            ->with('lopos')
            ->willReturn('loops');
        Injector::inst()->registerService($index, SearchIndex::class);
        $admin = new UserDocsAdmin();
        $admin->setRequest(new HTTPRequest('GET', 'admin/user-docs', $getVars));

        $html = $admin->getSearchQueryRecommendationLink();
        if ($expectedSuggestion === null) {
            $this->assertNull($html);
        } else {
            $this->assertSuggestionLink($expectedSuggestion, $html);
        }
    }

    public function testGetSearchQueryRecommendationLinkEscapesSuggestion(): void
    {
        $suggestion = '<b onclick="x">a & b</b>';
        $index = $this->createMock(SearchIndex::class);
        $index->method('getQueryRecommendation')->willReturn($suggestion);
        Injector::inst()->registerService($index, SearchIndex::class);
        $admin = new UserDocsAdmin();
        $admin->setRequest(new HTTPRequest('GET', 'admin/user-docs', ['q' => ['terms' => 'lopos']]));

        $this->assertSuggestionLink($suggestion, $admin->getSearchQueryRecommendationLink());
    }

    /**
     * Assert that the HTML is only a link to search for the suggestion, with the suggestion as its text.
     */
    private function assertSuggestionLink(string $suggestion, ?string $html): void
    {
        $this->assertNotNull($html);
        $document = new DOMDocument();
        $document->loadHTML('<meta charset="utf-8">' . $html, LIBXML_NOERROR);
        $body = $document->getElementsByTagName('body')->item(0);
        $this->assertSame(1, $body->childNodes->length);
        $link = $body->firstChild;
        $this->assertSame('a', $link->nodeName);
        $this->assertSame(0, $link->getElementsByTagName('*')->length);
        $this->assertSame($suggestion, $link->textContent);
        $this->assertSame(
            'admin/user-docs?' . http_build_query(['q' => ['terms' => $suggestion]]),
            $link->getAttribute('href')
        );
    }

    public static function provideGetSearchQueryRecommendationLinkNoSuggestion(): array
    {
        return [
            'null' => ['suggestion' => null],
            'empty string' => ['suggestion' => ''],
        ];
    }

    #[DataProvider('provideGetSearchQueryRecommendationLinkNoSuggestion')]
    public function testGetSearchQueryRecommendationLinkNoSuggestion(?string $suggestion): void
    {
        $index = $this->createMock(SearchIndex::class);
        $index->method('getQueryRecommendation')->willReturn($suggestion);
        Injector::inst()->registerService($index, SearchIndex::class);
        $admin = new UserDocsAdmin();
        $admin->setRequest(new HTTPRequest('GET', 'admin/user-docs', ['q' => ['terms' => 'loops']]));

        $this->assertNull($admin->getSearchQueryRecommendationLink());
    }

    private function useLocalisationFixtures(): void
    {
        UserDocsManifest::config()->set('documentation_roots', [
            'silverstripe/userdocs-admin:tests/php/fixtures/localisation' => true,
        ]);
    }

    private function useSpecialCharsFixtures(): void
    {
        UserDocsManifest::config()->set('documentation_roots', [
            'silverstripe/userdocs-admin:tests/php/fixtures/special_chars' => true,
        ]);
    }

    /**
     * Get a regex that matches an opening tag with these attribute values.
     * The attributes can be in any order, and the tag can have other attributes.
     */
    private function openingTagPattern(string $tag, array $attributes): string
    {
        $lookaheads = '';
        foreach ($attributes as $name => $value) {
            $lookaheads .= '(?=[^>]*\s' . preg_quote($name, '/') . '="' . preg_quote($value, '/') . '")';
        }
        return '/<' . preg_quote($tag, '/') . '\b' . $lookaheads . '[^>]*>/';
    }

    /**
     * Register the fixture modules in priority order (highest first), and build the manifest from their docs
     */
    private function useExtensionModules(array $moduleNames): void
    {
        $this->pushExtensionModules($moduleNames);
        $roots = [];
        foreach ($moduleNames as $moduleName) {
            $roots["test/module-$moduleName:docs"] = true;
        }
        UserDocsManifest::config()->set('documentation_roots', $roots);
        UserDocsManifest::singleton()->init();
    }

    /**
     * Copy a module resource into a temporary public folder, as if it was exposed.
     */
    private function exposeResource(string $resourcePath): void
    {
        $filesystem = new Filesystem();
        if (!$this->tempBaseFolder) {
            $this->tempBaseFolder = Path::join(TEMP_PATH, 'UserDocsAdminTest_' . uniqid());
            $filesystem->mkdir(Path::join($this->tempBaseFolder, PUBLIC_DIR));
            Director::config()->set('alternate_base_folder', $this->tempBaseFolder);
        }
        $resource = ModuleResourceLoader::singleton()->resolveResource($resourcePath);
        $filesystem->copy(
            $resource->getPath(),
            Path::join(Director::publicFolder(), RESOURCES_DIR, $resource->getRelativePath())
        );
    }
}
