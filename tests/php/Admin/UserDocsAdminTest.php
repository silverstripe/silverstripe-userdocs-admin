<?php

namespace SilverStripe\UserDocs\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Security;
use SilverStripe\UserDocs\Admin\UserDocsAdmin;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Tests\Admin\UserDocsAdminTest\TestUserDocsManifest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class UserDocsAdminTest extends FunctionalTest
{
    private const MODULE = 'silverstripe/userdocs-admin';

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the real site's manifest cache out of it
        Injector::inst()->registerService(
            new Psr16Cache(new ArrayAdapter()),
            CacheInterface::class . '.userdocsmanifest'
        );
        UserDocsManifest::config()->set('documentation_roots', [
            'silverstripe/userdocs-admin:tests/php/fixtures/docs' => true,
        ]);
        Injector::inst()->registerService(new TestUserDocsManifest(), UserDocsManifest::class);
        $this->logInWithPermission('ADMIN');
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

    public function testRenderDocWithImage(): void
    {
        UserDocsManifest::singleton()->init();

        $html = (new UserDocsAdmin())->renderDoc('getting_started/first_page');

        // Images point at the image action
        $this->assertStringContainsString(
            '<img src="admin/user-docs/image?module=silverstripe%2Fuserdocs-admin&amp;path=tests%2Fphp%2Ffixtures%2F'
            . 'docs%2Fen%2F01_getting_started%2Fimages%2Fscreenshot.png&amp;v=',
            $html
        );
    }

    public function testImage(): void
    {
        $path = 'tests/php/fixtures/docs/en/01_getting_started/images/screenshot.png';

        $response = $this->get('admin/user-docs/image?' . http_build_query([
            'module' => static::MODULE,
            'path' => $path,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeader('Content-Type'));
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $response->getHeader('Content-Security-Policy'));
        $this->assertStringContainsString('private', $response->getHeader('Cache-Control'));
        $this->assertStringContainsString('max-age=31536000', $response->getHeader('Cache-Control'));
        $filePath = ModuleLoader::getModule(static::MODULE)->getResource($path)->getPath();
        $this->assertSame('"' . hash_file('xxh3', $filePath) . '"', $response->getHeader('ETag'));
        $this->assertSame(file_get_contents($filePath), $response->getBody());
    }

    public static function provideImageNotFound(): array
    {
        return [
            'no query parameters' => [
                'query' => [],
            ],
            'query parameters are arrays' => [
                'query' => [
                    'module' => [UserDocsAdminTest::MODULE],
                    'path' => ['tests/php/fixtures/docs/en/01_getting_started/images/screenshot.png'],
                ],
            ],
            'module without docs' => [
                'query' => [
                    'module' => 'silverstripe/framework',
                    'path' => 'tests/php/fixtures/docs/en/01_getting_started/images/screenshot.png',
                ],
            ],
            'file outside documentation root' => [
                'query' => [
                    'module' => UserDocsAdminTest::MODULE,
                    'path' => 'client/images/missing-image-placeholder.png',
                ],
            ],
            'file is not an image' => [
                'query' => [
                    'module' => UserDocsAdminTest::MODULE,
                    'path' => 'tests/php/fixtures/docs/en/01_getting_started/01_first_page.md',
                ],
            ],
        ];
    }

    #[DataProvider('provideImageNotFound')]
    public function testImageNotFound(array $query): void
    {
        $response = $this->get('admin/user-docs/image?' . http_build_query($query));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testImageWithoutCmsAccess(): void
    {
        $this->logInWithPermission('SOME_OTHER_PERMISSION');

        $response = $this->get('admin/user-docs/image?' . http_build_query([
            'module' => static::MODULE,
            'path' => 'tests/php/fixtures/docs/en/01_getting_started/images/screenshot.png',
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotSame('image/png', $response->getHeader('Content-Type'));
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

    public function testGetDocLink(): void
    {
        $admin = UserDocsAdmin::singleton();

        $this->assertSame(
            $admin->Link('docs/' . UserDocsManifest::encodeSlug('section/c# & 100%')),
            $admin->getDocLink('section/c# & 100%')
        );
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
}
