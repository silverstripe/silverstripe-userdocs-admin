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
            'before the first heading in the table of contents' => [
                'markdown' => "# Title\n\nIntro\n\n## Section\n\n## Another section",
                'expected' => ['h1', 'p', 'toc', 'h2', 'h2'],
            ],
            'before a heading that comes before the h1' => [
                'markdown' => "## Section\n\n# Title",
                'expected' => ['toc', 'h2', 'h1'],
            ],
            'later h1s are used when min level is 1' => [
                'markdown' => "# Title\n\nIntro\n\n# Another title\n\n## Section",
                'expected' => ['h1', 'p', 'toc', 'h1', 'h2'],
                'minLevel' => 1,
            ],
            'later h1s are ignored when min level is 2' => [
                'markdown' => "# Title\n\nIntro\n\n# Another title\n\n## Section",
                'expected' => ['h1', 'p', 'h1', 'toc', 'h2'],
            ],
            'headings below min level are ignored' => [
                'markdown' => "# Title\n\n## Section\n\n### Subsection",
                'expected' => ['h1', 'h2', 'toc', 'h3'],
                'minLevel' => 3,
            ],
            'headings above max level are ignored' => [
                'markdown' => "# Title\n\n### Subsection\n\n## Section",
                'expected' => ['h1', 'h3', 'toc', 'h2'],
                'maxLevel' => 2,
            ],
            'headings that are not top level are ignored' => [
                'markdown' => "# Title\n\n> ## Quoted\n\n## Section",
                'expected' => ['h1', 'blockquote', 'h2', 'toc', 'h2'],
            ],
            'after the first h1 when there is no heading in the table of contents' => [
                'markdown' => "Intro\n\n# Title\n\nText\n\n> ## Quoted",
                'expected' => ['p', 'h1', 'toc', 'p', 'blockquote', 'h2'],
            ],
            'at the top when there is no h1 and no heading in the table of contents' => [
                'markdown' => "Intro\n\n> ## Quoted",
                'expected' => ['toc', 'p', 'blockquote', 'h2'],
            ],
            'does nothing when position is not placeholder' => [
                'markdown' => "# Title\n\nIntro\n\n## Section",
                'expected' => ['toc', 'h1', 'p', 'h2'],
                'position' => 'top',
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsed')]
    public function testOnDocumentParsed(
        string $markdown,
        array $expected,
        string $position = 'placeholder',
        int $minLevel = 2,
        int $maxLevel = 6,
    ): void {
        $this->assertSame($expected, $this->convertToBlockOrder($markdown, $position, $minLevel, $maxLevel));
    }

    /**
     * Convert markdown and return the top-level blocks in order, with the table of contents as "toc"
     */
    private function convertToBlockOrder(string $markdown, string $position, int $minLevel, int $maxLevel): array
    {
        $environment = new Environment([
            'table_of_contents' => [
                'position' => $position,
                'placeholder' => '[TOC]',
                'min_heading_level' => $minLevel,
                'max_heading_level' => $maxLevel,
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new TableOfContentsExtension());
        $environment->addEventListener(
            DocumentParsedEvent::class,
            [new TableOfContentsEventHandler(), 'onDocumentParsed']
        );
        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        preg_match_all('/^<(h[1-6]|p|blockquote|ul class="table-of-contents")[\s>]/m', $html, $matches);
        return array_map(fn (string $tag) => $tag === 'ul class="table-of-contents"' ? 'toc' : $tag, $matches[1]);
    }
}
