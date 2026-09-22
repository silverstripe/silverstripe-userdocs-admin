<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkRenderer;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\HeadingPermalinkWrapper;

class HeadingPermalinkWrapperTest extends SapphireTest
{
    private const BASE_URL = '/admin/user-docs/docs/getting_started/first_page';

    public static function provideRender(): array
    {
        return [
            'plain heading' => [
                'markdown' => '## Some heading',
                'expectedLabel' => 'Permalink: Some heading',
            ],
            'heading with inline formatting' => [
                'markdown' => '## Some *important* `code`',
                'expectedLabel' => 'Permalink: Some important code',
            ],
            'heading with characters that need escaping' => [
                'markdown' => '## Tom & "Jerry"',
                'expectedLabel' => 'Permalink: Tom &amp; &quot;Jerry&quot;',
            ],
        ];
    }

    #[DataProvider('provideRender')]
    public function testRender(string $markdown, string $expectedLabel): void
    {
        $environment = new Environment([
            'heading_permalink' => ['aria_hidden' => false],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addRenderer(
            HeadingPermalink::class,
            new HeadingPermalinkWrapper(new HeadingPermalinkRenderer(), static::BASE_URL),
            99999
        );

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        $this->assertMatchesRegularExpression(
            '#<a\b(?=[^>]*\saria-label="' . preg_quote($expectedLabel, '#') . '")'
            . '(?=[^>]*\shref="' . preg_quote(static::BASE_URL, '#') . '\#)[^>]*>#',
            $html
        );
    }
}
