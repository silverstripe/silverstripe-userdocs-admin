<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Markdown\MissingDocLinkEventHandler;

class MissingDocLinkEventHandlerTest extends SapphireTest
{
    private const BASE_URL = '/admin/user-docs/docs';

    protected function setUp(): void
    {
        parent::setUp();
        $manifest = $this->createStub(UserDocsManifest::class);
        $manifest->method('getLocalisedDocData')->willReturn([
            'section' => ['hasContent' => false],
            'section/page' => ['hasContent' => true],
            'section/été' => ['hasContent' => true],
        ]);
        Injector::inst()->registerService($manifest, UserDocsManifest::class);
    }

    public static function provideOnDocumentParsed(): array
    {
        return [
            'existing doc' => [
                'url' => '/admin/user-docs/docs/section/page',
                'isLink' => true,
            ],
            'existing doc with an anchor and query string' => [
                'url' => '/admin/user-docs/docs/section/page?a=b#missing-heading',
                'isLink' => true,
            ],
            'existing doc with a trailing slash' => [
                'url' => '/admin/user-docs/docs/section/page/',
                'isLink' => true,
            ],
            'existing doc with an encoded slug' => [
                'url' => '/admin/user-docs/docs/section/%C3%A9t%C3%A9',
                'isLink' => true,
            ],
            'missing doc' => [
                'url' => '/admin/user-docs/docs/other_section/page',
                'isLink' => false,
            ],
            'doc without content' => [
                'url' => '/admin/user-docs/docs/section',
                'isLink' => false,
            ],
            'URL which only starts with the same characters as the base URL' => [
                'url' => '/admin/user-docs/docsection/missing',
                'isLink' => true,
            ],
            'other URL in the CMS' => [
                'url' => '/admin/pages',
                'isLink' => true,
            ],
            'absolute URL' => [
                'url' => 'https://www.silverstripe.org/admin/user-docs/docs/missing',
                'isLink' => true,
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsed')]
    public function testOnDocumentParsed(string $url, bool $isLink): void
    {
        $html = $this->convert("Before [the link]($url) after", static::BASE_URL);

        if ($isLink) {
            $href = preg_quote(htmlspecialchars($url), '#');
            $this->assertMatchesRegularExpression('#<a\b[^>]*\bhref="' . $href . '"[^>]*>the link</a>#', $html);
        } else {
            $this->assertSame("<p>Before the link after</p>\n", $html);
        }
    }

    public function testOnDocumentParsedBaseUrlWithTrailingSlash(): void
    {
        $html = $this->convert('[the link](/admin/user-docs/docs/missing)', static::BASE_URL . '/');

        $this->assertSame("<p>the link</p>\n", $html);
    }

    public function testOnDocumentParsedKeepsLinkContent(): void
    {
        $markdown = implode("\n\n", [
            '[**Bold** and `code`](/admin/user-docs/docs/missing)',
            '[First](/admin/user-docs/docs/missing) and [second](/admin/user-docs/docs/also_missing)',
        ]);

        $html = $this->convert($markdown, static::BASE_URL);

        $this->assertSame(
            "<p><strong>Bold</strong> and <code>code</code></p>\n<p>First and second</p>\n",
            $html
        );
    }

    private function convert(string $markdown, string $baseUrl): string
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addEventListener(
            DocumentParsedEvent::class,
            [new MissingDocLinkEventHandler($baseUrl), 'onDocumentParsed']
        );
        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
    }
}
