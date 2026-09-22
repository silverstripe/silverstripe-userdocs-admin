<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\TableOfContentsEventHandler;

class TableOfContentsEventHandlerTest extends SapphireTest
{
    public static function provideOnDocumentParsed(): array
    {
        return [
            'after the first h1' => [
                'markdown' => "Intro\n\n# Title\n\n## Section",
                'position' => 'placeholder',
                'expected' => ['p', 'h1', 'toc', 'h2'],
            ],
            'only after the first of several h1s' => [
                'markdown' => "# Title\n\n# Another title\n\n## Section",
                'position' => 'placeholder',
                'expected' => ['h1', 'toc', 'h1', 'h2'],
            ],
            'at the top when there is no h1' => [
                'markdown' => "Intro\n\n## Section",
                'position' => 'placeholder',
                'expected' => ['toc', 'p', 'h2'],
            ],
            'does nothing when position is not placeholder' => [
                'markdown' => "Intro\n\n# Title\n\n## Section",
                'position' => 'top',
                'expected' => ['toc', 'p', 'h1', 'h2'],
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsed')]
    public function testOnDocumentParsed(string $markdown, string $position, array $expected): void
    {
        $this->assertSame($expected, $this->convertToBlockOrder($markdown, $position));
    }

    /**
     * Convert markdown and return the top-level blocks in order, with the table of contents as "toc"
     */
    private function convertToBlockOrder(string $markdown, string $position): array
    {
        $environment = new Environment([
            'table_of_contents' => ['position' => $position, 'placeholder' => '[TOC]'],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new TableOfContentsExtension());
        $environment->addEventListener(
            DocumentParsedEvent::class,
            [new TableOfContentsEventHandler(), 'onDocumentParsed']
        );
        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        preg_match_all('/^<(h1|h2|p|ul class="table-of-contents")[\s>]/m', $html, $matches);
        return array_map(fn (string $tag) => str_starts_with($tag, 'ul') ? 'toc' : $tag, $matches[1]);
    }
}
