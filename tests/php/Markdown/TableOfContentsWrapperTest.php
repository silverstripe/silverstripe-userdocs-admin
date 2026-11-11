<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Renderer\Block\ListBlockRenderer;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContents;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsRenderer;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\TableOfContentsWrapper;

class TableOfContentsWrapperTest extends SapphireTest
{
    public static function provideRender(): array
    {
        return [
            'plain base URL' => [
                'baseUrl' => '/admin/user-docs/docs/section/page',
                'expectedHref' => '/admin/user-docs/docs/section/page#content-my-heading',
            ],
            'base URL with back reference syntax' => [
                'baseUrl' => '/docs/$1\\1${1}',
                'expectedHref' => '/docs/$1\\1${1}#content-my-heading',
            ],
            'base URL with HTML special characters' => [
                'baseUrl' => '/docs/"><script>',
                'expectedHref' => '/docs/&quot;&gt;&lt;script&gt;#content-my-heading',
            ],
        ];
    }

    #[DataProvider('provideRender')]
    public function testRender(string $baseUrl, string $expectedHref): void
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new TableOfContentsExtension());
        $environment->addRenderer(
            TableOfContents::class,
            new TableOfContentsWrapper(new TableOfContentsRenderer(new ListBlockRenderer()), $baseUrl),
            99999
        );

        $html = (new MarkdownConverter($environment))->convert('## My heading')->getContent();

        $this->assertMatchesRegularExpression(
            '#<nav class="toc-container"[^>]*>.*href="' . preg_quote($expectedHref, '#') . '"#s',
            $html
        );
    }
}
