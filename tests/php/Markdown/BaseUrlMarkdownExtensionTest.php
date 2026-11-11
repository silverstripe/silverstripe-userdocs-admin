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

class BaseUrlMarkdownExtensionTest extends SapphireTest
{
    private const BASE_URL = '/admin/user-docs/docs';

    private const IMAGE_URL = '/admin/user-docs/image';

    private const MODULE = 'silverstripe/userdocs-admin';

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
            . '<h2 id="toc-heading" class="toc-heading">On this page</h2>'
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

    private function getPlaceholderUrl(): string
    {
        return ModuleResourceLoader::resourceURL(
            'silverstripe/userdocs-admin:client/images/missing-image-placeholder.png'
        );
    }

    private function convert(string $markdown, array $docData, ?Environment $environment = null): string
    {
        if (!$environment) {
            $environment = new Environment();
            $environment->addExtension(new CommonMarkCoreExtension());
        }
        $environment->addExtension(new BaseUrlMarkdownExtension(static::BASE_URL, $docData, static::IMAGE_URL));
        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
    }
}
