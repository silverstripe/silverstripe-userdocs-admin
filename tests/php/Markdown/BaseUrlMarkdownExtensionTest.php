<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\UserDocs\Markdown\BaseUrlMarkdownExtension;
use SilverStripe\View\SSViewer;

class BaseUrlMarkdownExtensionTest extends SapphireTest
{
    private const BASE_URL = '/admin/user-docs/docs';

    private const IMAGE_URL = '/admin/user-docs/image';

    private const MODULE = 'silverstripe/userdocs-admin';

    protected function setUp(): void
    {
        parent::setUp();
        SSViewer::config()->set('enable_base_tag', true);
    }

    private static function docData(): array
    {
        return [
            'isIndex' => false,
            'module' => 'silverstripe/userdocs-admin',
            'locale' => 'en',
            'slug' => 'getting_started/first_page',
            'rootPathInModule' => 'tests/php/fixtures/docs',
            'pathFromLocale' => '01_getting_started/01_first_page.md',
        ];
    }

    /**
     * Doc data for an extension doc in a different folder from the doc in docData(), with its own images
     */
    private static function extensionDocData(): array
    {
        return [
            'isIndex' => false,
            'module' => 'silverstripe/userdocs-admin',
            'locale' => 'en',
            'slug' => 'getting_started/first_page',
            'rootPathInModule' => 'tests/php/fixtures/localisation',
            'pathFromLocale' => '01_section/01_first.md',
        ];
    }

    private static function indexDocData(): array
    {
        return [
            'isIndex' => true,
            'module' => 'silverstripe/userdocs-admin',
            'locale' => 'en',
            'slug' => 'getting_started',
            'rootPathInModule' => 'tests/php/fixtures/docs',
            'pathFromLocale' => '01_getting_started/index.md',
        ];
    }

    public static function provideOnDocumentParsedLinks(): array
    {
        return [
            'relative link from page' => [
                'isIndex' => false,
                'url' => 'second_page',
                'expected' => '/admin/user-docs/docs/getting_started/second_page',
            ],
            'relative link to another section' => [
                'isIndex' => false,
                'url' => '../other_section/page',
                'expected' => '/admin/user-docs/docs/other_section/page',
            ],
            'relative link from index page' => [
                'isIndex' => true,
                'url' => 'second_page',
                'expected' => '/admin/user-docs/docs/getting_started/second_page',
            ],
            'link to index page of the same folder' => [
                'isIndex' => false,
                'url' => '.',
                'expected' => '/admin/user-docs/docs/getting_started',
            ],
            'link to index page of the same folder with anchor' => [
                'isIndex' => false,
                'url' => '.#details',
                'expected' => '/admin/user-docs/docs/getting_started#details',
            ],
            'relative link with query string' => [
                'isIndex' => false,
                'url' => 'second_page?a=b/../c',
                'expected' => '/admin/user-docs/docs/getting_started/second_page?a=b%2F..%2Fc',
            ],
            'anchor link from page' => [
                'isIndex' => false,
                'url' => '#details',
                'expected' => '/admin/user-docs/docs/getting_started/first_page#details',
            ],
            'anchor link from index page' => [
                'isIndex' => true,
                'url' => '#details',
                'expected' => '/admin/user-docs/docs/getting_started#details',
            ],
            'root-relative link' => [
                'isIndex' => false,
                'url' => '/other_section/page',
                'expected' => '/admin/user-docs/docs/other_section/page',
            ],
            'absolute URL' => [
                'isIndex' => false,
                'url' => 'https://www.silverstripe.org/',
                'expected' => 'https://www.silverstripe.org/',
            ],
            'protocol-relative URL' => [
                'isIndex' => false,
                'url' => '//www.silverstripe.org/',
                'expected' => '//www.silverstripe.org/',
            ],
            'tel link' => [
                'isIndex' => false,
                'url' => 'tel:+6400000000',
                'expected' => 'tel:+6400000000',
            ],
            'relative link with a scheme later in the URL' => [
                'isIndex' => false,
                'url' => 'second_page?contact=mailto:someone',
                'expected' => '/admin/user-docs/docs/getting_started/second_page?contact=mailto%3Asomeone',
            ],
            'mailto link' => [
                'isIndex' => false,
                'url' => 'mailto:someone@example.com',
                'expected' => 'mailto:someone@example.com',
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsedLinks')]
    public function testOnDocumentParsedLinks(bool $isIndex, string $url, string $expected): void
    {
        $docData = $isIndex ? static::indexDocData() : static::docData();

        $html = $this->convert("[link]($url)", $docData);

        $this->assertSame('<p><a href="' . $expected . '">link</a></p>', trim($html));
    }

    public static function provideOnDocumentParsedLinksWithFragmentPrefix(): array
    {
        return [
            'anchor link' => [
                'url' => '#details',
                'expected' => '/admin/user-docs/docs/getting_started/first_page#userdocs-details',
            ],
            'relative link with anchor' => [
                'url' => 'second_page#details',
                'expected' => '/admin/user-docs/docs/getting_started/second_page#userdocs-details',
            ],
            'root-relative link with anchor' => [
                'url' => '/other_section/page#details',
                'expected' => '/admin/user-docs/docs/other_section/page#userdocs-details',
            ],
            'empty anchor' => [
                'url' => 'second_page#',
                'expected' => '/admin/user-docs/docs/getting_started/second_page',
            ],
            'absolute URL with anchor' => [
                'url' => 'https://www.silverstripe.org/#details',
                'expected' => 'https://www.silverstripe.org/#details',
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsedLinksWithFragmentPrefix')]
    public function testOnDocumentParsedLinksWithFragmentPrefix(string $url, string $expected): void
    {
        $environment = new Environment(['heading_permalink' => ['fragment_prefix' => 'userdocs']]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new HeadingPermalinkExtension());

        $html = $this->convert("[link]($url)", static::docData(), $environment);

        $this->assertSame('<p><a href="' . $expected . '">link</a></p>', trim($html));
    }

    public function testOnDocumentParsedLinksWithoutBaseTag(): void
    {
        SSViewer::config()->set('enable_base_tag', false);
        $environment = new Environment(['heading_permalink' => ['fragment_prefix' => 'userdocs']]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new HeadingPermalinkExtension());

        $html = $this->convert('[anchor](#details) [link](second_page#details)', static::docData(), $environment);

        $this->assertSame(
            '<p><a href="#userdocs-details">anchor</a> '
            . '<a href="/admin/user-docs/docs/getting_started/second_page#userdocs-details">link</a></p>',
            trim($html)
        );
    }

    public function testOnDocumentParsedLinksEncodesSlug(): void
    {
        $docData = ['slug' => 'c# & 100%/page'] + static::docData();

        $html = $this->convert('[link](other) [anchor](#details)', $docData);

        $this->assertSame(
            '<p><a href="/admin/user-docs/docs/c%23%20%26%20100%25/other">link</a> '
            . '<a href="/admin/user-docs/docs/c%23%20%26%20100%25/page#details">anchor</a></p>',
            trim($html)
        );
    }

    public static function provideOnDocumentParsedImages(): array
    {
        return [
            'image from page' => [
                'locale' => 'en_US',
                'docData' => static::docData(),
                'url' => 'images/screenshot.png',
                'expectedPath' => 'tests/php/fixtures/docs/en/01_getting_started/images/screenshot.png',
            ],
            'image with a file name which CommonMark encodes' => [
                'locale' => 'en_US',
                'docData' => static::docData(),
                'url' => '<images/été screenshot.png>',
                'expectedPath' => 'tests/php/fixtures/docs/en/01_getting_started/images/été screenshot.png',
            ],
            'image from index page resolves from its own directory' => [
                'locale' => 'en_US',
                'docData' => static::indexDocData(),
                'url' => 'images/screenshot.png',
                'expectedPath' => 'tests/php/fixtures/docs/en/01_getting_started/images/screenshot.png',
            ],
            // Docs are only rendered in a locale the user's locale falls back to
            'translated doc uses English image when it has no image of its own' => [
                'locale' => 'fr_FR',
                'docData' => [
                    'isIndex' => false,
                    'module' => 'silverstripe/userdocs-admin',
                    'locale' => 'fr',
                    'slug' => 'section/first',
                    'rootPathInModule' => 'tests/php/fixtures/localisation',
                    'pathFromLocale' => '01_section/01_first.md',
                ],
                'url' => 'images/screenshot.png',
                'expectedPath' => 'tests/php/fixtures/localisation/en/01_section/images/screenshot.png',
            ],
            'translated doc uses image for its language before English image' => [
                'locale' => 'fr_CA',
                'docData' => [
                    'isIndex' => false,
                    'module' => 'silverstripe/userdocs-admin',
                    'locale' => 'fr_CA',
                    'slug' => 'section/first',
                    'rootPathInModule' => 'tests/php/fixtures/localisation',
                    'pathFromLocale' => '01_section/01_first.md',
                ],
                'url' => 'images/french_only.png',
                'expectedPath' => 'tests/php/fixtures/localisation/fr/01_section/images/french_only.png',
            ],
            // The documentation root is in a folder named "fr", and only the English docs have the image
            'root path contains folder named like locale' => [
                'locale' => 'fr_FR',
                'docData' => [
                    'isIndex' => false,
                    'module' => 'silverstripe/userdocs-admin',
                    'locale' => 'fr',
                    'slug' => 'section/page',
                    'rootPathInModule' => 'tests/php/fixtures/locale_name_in_root/fr/userhelp',
                    'pathFromLocale' => '01_section/01_page.md',
                ],
                'url' => 'images/screenshot.png',
                'expectedPath' => 'tests/php/fixtures/locale_name_in_root/fr/userhelp/en/01_section/images/'
                    . 'screenshot.png',
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsedImages')]
    public function testOnDocumentParsedImages(
        string $locale,
        array $docData,
        string $url,
        string $expectedPath
    ): void {
        i18n::set_locale($locale);

        $html = $this->convert("![Alt text]($url)", $docData);

        $filePath = ModuleLoader::getModule(static::MODULE)->getResource($expectedPath)->getPath();
        $expectedUrl = static::IMAGE_URL . '?' . http_build_query([
            'module' => static::MODULE,
            'path' => $expectedPath,
            'v' => hash_file('xxh3', $filePath),
        ]);
        $this->assertSame(
            '<p><img src="' . htmlspecialchars($expectedUrl) . '" alt="Alt text" /></p>',
            trim($html)
        );
    }

    public function testOnDocumentParsedImageOutsideRoot(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                "Image '../../../composer.json' in 'silverstripe/userdocs-admin:"
                . "tests/php/fixtures/docs/en/01_getting_started/01_first_page.md' is outside the documentation root"
            );
        Injector::inst()->registerService($logger, LoggerInterface::class);

        $html = $this->convert('![Outside](../../../composer.json)', static::docData());

        $this->assertSame('<p><img src="' . $this->getPlaceholderUrl() . '" alt="Outside" /></p>', trim($html));
    }

    public function testOnDocumentParsedMissingImage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                "Could not find image 'silverstripe/userdocs-admin:"
                . "tests/php/fixtures/docs/en/01_getting_started/images/missing.png'"
            );
        Injector::inst()->registerService($logger, LoggerInterface::class);

        $html = $this->convert('![Missing](images/missing.png)', static::docData());

        $this->assertSame('<p><img src="' . $this->getPlaceholderUrl() . '" alt="Missing" /></p>', trim($html));
    }

    public function testOnDocumentParsedUnsupportedImage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                "Image 'silverstripe/userdocs-admin:"
                . "tests/php/fixtures/docs/en/01_getting_started/02_second_page.md' is not a supported type of image"
            );
        Injector::inst()->registerService($logger, LoggerInterface::class);

        $html = $this->convert('![Not an image](02_second_page.md)', static::docData());

        $this->assertSame('<p><img src="' . $this->getPlaceholderUrl() . '" alt="Not an image" /></p>', trim($html));
    }

    public function testOnDocumentParsedWithExtension(): void
    {
        // The extension's file is in a different folder, which has its own copy of the image
        $extensionDocData = static::extensionDocData();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        Injector::inst()->registerService($logger, LoggerInterface::class);
        $markdown = "![Original](images/screenshot.png)\n\n"
            . BaseUrlMarkdownExtension::getExtensionMarker(0) . "\n\n"
            . '![Extension](images/screenshot.png)';

        $html = $this->convert($markdown, static::docData(), extensionData: [0 => $extensionDocData]);

        $originalUrl = $this->getImageUrl('tests/php/fixtures/docs/en/01_getting_started/images/screenshot.png');
        $extensionUrl = $this->getImageUrl('tests/php/fixtures/localisation/en/01_section/images/screenshot.png');
        $this->assertSame(
            '<p><img src="' . htmlspecialchars($originalUrl) . '" alt="Original" /></p>' . "\n"
            . '<p><img src="' . htmlspecialchars($extensionUrl) . '" alt="Extension" /></p>',
            trim($html)
        );
    }

    public function testOnDocumentParsedWithMultipleExtensions(): void
    {
        $extensionData = [
            0 => static::extensionDocData(),
            1 => [
                'isIndex' => false,
                'module' => 'silverstripe/userdocs-admin',
                'locale' => 'en',
                'slug' => 'getting_started/first_page',
                'rootPathInModule' => 'tests/php/fixtures/locale_name_in_root/fr/userhelp',
                'pathFromLocale' => '01_section/01_page.md',
            ],
        ];
        $markdown = "![Original](images/screenshot.png)\n\n"
            . BaseUrlMarkdownExtension::getExtensionMarker(0) . "\n\n"
            . "![First extension](images/screenshot.png)\n\n"
            . BaseUrlMarkdownExtension::getExtensionMarker(1) . "\n\n"
            . '![Second extension](images/screenshot.png)';

        $html = $this->convert($markdown, static::docData(), extensionData: $extensionData);

        preg_match_all('#<img src="([^"]+)"#', $html, $matches);
        $this->assertSame([
            $this->getImageUrl('tests/php/fixtures/docs/en/01_getting_started/images/screenshot.png'),
            $this->getImageUrl('tests/php/fixtures/localisation/en/01_section/images/screenshot.png'),
            $this->getImageUrl(
                'tests/php/fixtures/locale_name_in_root/fr/userhelp/en/01_section/images/screenshot.png'
            ),
        ], array_map('htmlspecialchars_decode', $matches[1]));
    }

    public function testOnDocumentParsedLinksInExtension(): void
    {
        // The extension is an index file for the same slug, so its relative links don't need ".."
        $extensionDocData = [
            ...static::docData(),
            'isIndex' => true,
            'pathFromLocale' => '01_getting_started/first_page/index.md',
        ];
        $markdown = "[original](other_page)\n\n"
            . BaseUrlMarkdownExtension::getExtensionMarker(0) . "\n\n"
            . '[extension](other_page)';

        $html = $this->convert($markdown, static::docData(), extensionData: [0 => $extensionDocData]);

        $this->assertSame(
            '<p><a href="/admin/user-docs/docs/getting_started/other_page">original</a></p>' . "\n"
            . '<p><a href="/admin/user-docs/docs/getting_started/first_page/other_page">extension</a></p>',
            trim($html)
        );
    }

    public function testOnDocumentParsedMissingImageInExtension(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                "Could not find image 'silverstripe/userdocs-admin:"
                . "tests/php/fixtures/localisation/en/01_section/images/missing.png'"
            );
        Injector::inst()->registerService($logger, LoggerInterface::class);
        $extensionDocData = static::extensionDocData();
        $markdown = "Original content\n\n"
            . BaseUrlMarkdownExtension::getExtensionMarker(0) . "\n\n"
            . '![Missing](images/missing.png)';

        $this->convert($markdown, static::docData(), extensionData: [0 => $extensionDocData]);
    }

    public function testOnDocumentParsedWithMarkerInsideCodeBlock(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                "Could not find where the content from '/path/to/extension.md' starts,"
                . ' so its links and images may not work.'
                . ' Check the content before it for an unclosed code block or HTML block.'
            );
        Injector::inst()->registerService($logger, LoggerInterface::class);
        // The original doc has a code block that is never closed, so it includes the marker
        $markdown = "```\nsome code\n\n" . BaseUrlMarkdownExtension::getExtensionMarker(0) . "\n\nExtension content";

        $this->convert($markdown, static::docData(), extensionData: [0 => ['filePath' => '/path/to/extension.md']]);
    }

    public function testOnDocumentParsedWithMarkerInsideHtmlBlock(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                "Could not find where the content from '/path/to/extension.md' starts,"
                . ' so its links and images may not work.'
                . ' Check the content before it for an unclosed code block or HTML block.'
            );
        Injector::inst()->registerService($logger, LoggerInterface::class);
        // The original doc has a <pre> element that is never closed, so the HTML block includes the marker
        $markdown = "<pre>\nsome code\n\n" . BaseUrlMarkdownExtension::getExtensionMarker(0) . "\n\nExtension content";

        $this->convert($markdown, static::docData(), extensionData: [0 => ['filePath' => '/path/to/extension.md']]);
    }

    public function testOnDocumentParsedWithUnknownExtensionMarker(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                "No extension doc data for marker '5', so the content after it"
                . ' uses the paths of the content before it.'
            );
        Injector::inst()->registerService($logger, LoggerInterface::class);
        $markdown = "Original content\n\n" . BaseUrlMarkdownExtension::getExtensionMarker(5) . "\n\nExtension content";

        $html = $this->convert($markdown, static::docData(), extensionData: []);

        // The marker is still removed
        $this->assertSame("<p>Original content</p>\n<p>Extension content</p>", trim($html));
    }

    public function testRegister(): void
    {
        $environment = new Environment([
            'heading_permalink' => ['id_prefix' => '', 'fragment_prefix' => '', 'symbol' => '#'],
            'table_of_contents' => ['position' => 'top'],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new TableOfContentsExtension());

        $html = $this->convert("## Details\n\nSome details.", static::docData(), $environment);

        $docUrl = '/admin/user-docs/docs/getting_started/first_page';
        $this->assertStringContainsString(
            '<nav class="toc-container" aria-labelledby="toc-heading">'
            . '<h2 id="toc-heading" class="toc-heading">Quick links</h2>'
            . '<ul class="table-of-contents">' . "\n"
            . '<li><a href="' . $docUrl . '#details">Details</a></li>',
            $html
        );
        $this->assertStringContainsString(
            '<a id="details" href="' . $docUrl . '#details" class="heading-permalink"',
            $html
        );
        $this->assertStringNotContainsString('href="#', $html);
    }

    public function testRegisterWithoutBaseTag(): void
    {
        SSViewer::config()->set('enable_base_tag', false);
        $environment = new Environment([
            'heading_permalink' => ['id_prefix' => '', 'fragment_prefix' => '', 'symbol' => '#'],
            'table_of_contents' => ['position' => 'top'],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new TableOfContentsExtension());

        $html = $this->convert("## Details\n\nSome details.", static::docData(), $environment);

        $this->assertStringContainsString('<li><a href="#details">Details</a></li>', $html);
        $this->assertStringContainsString('<a id="details" href="#details" class="heading-permalink"', $html);
    }

    private function getPlaceholderUrl(): string
    {
        return ModuleResourceLoader::resourceURL(
            'silverstripe/userdocs-admin:client/images/missing-image-placeholder.png'
        );
    }

    /**
     * Get the URL of the image action for an image in this module.
     */
    private function getImageUrl(string $pathInModule): string
    {
        $filePath = ModuleLoader::getModule(static::MODULE)->getResource($pathInModule)->getPath();
        return static::IMAGE_URL . '?' . http_build_query([
            'module' => static::MODULE,
            'path' => $pathInModule,
            'v' => hash_file('xxh3', $filePath),
        ]);
    }

    private function convert(
        string $markdown,
        array $docData,
        ?Environment $environment = null,
        array $extensionData = []
    ): string {
        if (!$environment) {
            $environment = new Environment();
            $environment->addExtension(new CommonMarkCoreExtension());
        }
        $environment->addExtension(
            new BaseUrlMarkdownExtension(static::BASE_URL, $docData, static::IMAGE_URL, $extensionData)
        );
        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
    }
}
