<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\LinkFixerEventHandler;

class LinkFixerEventHandlerTest extends SapphireTest
{
    public static function provideOnDocumentParsed(): array
    {
        return [
            'markdown file in the same folder' => [
                'url' => '02_second_page.md',
                'expected' => 'second_page',
            ],
            'markdown file in a sub folder' => [
                'url' => '03_nested_section/child_page.md',
                'expected' => 'nested_section/child_page',
            ],
            'index file of a sub folder' => [
                'url' => '03_nested_section/index.md',
                'expected' => 'nested_section',
            ],
            'markdown file in a parent folder' => [
                'url' => '../02_other_section/01_page.md',
                'expected' => '../other_section/page',
            ],
            'markdown file with an anchor' => [
                'url' => '02_second_page.md#details',
                'expected' => 'second_page#details',
            ],
            'slug' => [
                'url' => 'second_page',
                'expected' => 'second_page',
            ],
            'anchor' => [
                'url' => '#details',
                'expected' => '#details',
            ],
            'absolute URL to a markdown file' => [
                'url' => 'https://github.com/silverstripe/silverstripe-userdocs-admin/blob/1/README.md',
                'expected' => 'https://github.com/silverstripe/silverstripe-userdocs-admin/blob/1/README.md',
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsed')]
    public function testOnDocumentParsed(string $url, string $expected): void
    {
        $this->assertSame(['link' => $expected], $this->convertLinks(["[link]($url)"]));
    }

    public function testOnDocumentParsedAfterAbsoluteUrl(): void
    {
        $links = $this->convertLinks([
            '[external](https://www.silverstripe.org/)',
            '[internal](02_second_page.md)',
        ]);

        $this->assertSame(['external' => 'https://www.silverstripe.org/', 'internal' => 'second_page'], $links);
    }

    /**
     * Convert markdown paragraphs and get the href of each link, keyed by link text
     */
    private function convertLinks(array $paragraphs): array
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addEventListener(DocumentParsedEvent::class, [new LinkFixerEventHandler(), 'onDocumentParsed']);
        $html = (new MarkdownConverter($environment))->convert(implode("\n\n", $paragraphs))->getContent();

        preg_match_all('#<a href="([^"]*)">([^<]*)</a>#', $html, $matches);
        return array_combine($matches[2], $matches[1]);
    }
}
