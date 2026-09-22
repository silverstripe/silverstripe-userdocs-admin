<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\IdPrefixEventHandler;

class IdPrefixEventHandlerTest extends SapphireTest
{
    public static function provideOnDocumentParsed(): array
    {
        return [
            'custom ID on a paragraph' => [
                'markdown' => "Some text.\n{#my-id}",
                'config' => [],
                'expected' => '<p id="userdocs-my-id">',
            ],
            'custom ID on an inline element' => [
                'markdown' => '[link](https://example.com){#my-id}',
                'config' => [],
                'expected' => '<a id="userdocs-my-id" href="https://example.com">',
            ],
            'custom ID on a heading is only prefixed once' => [
                'markdown' => '## Heading {#my-id}',
                'config' => [],
                'expected' => '<h2 id="userdocs-my-id">',
            ],
            'generated ID on a heading is only prefixed once' => [
                'markdown' => '## Heading',
                'config' => [],
                'expected' => '<h2 id="userdocs-heading">',
            ],
            'custom ID on a heading without permalinks' => [
                'markdown' => '###### Heading {#my-id}',
                'config' => ['max_heading_level' => 5],
                'expected' => '<h6 id="userdocs-my-id">',
            ],
            'custom ID on a heading when IDs are not applied to headings' => [
                'markdown' => '## Heading {#my-id}',
                'config' => ['apply_id_to_heading' => false],
                'expected' => '<h2 id="userdocs-my-id">',
            ],
            'no prefix' => [
                'markdown' => "Some text.\n{#my-id}",
                'config' => ['id_prefix' => ''],
                'expected' => '<p id="my-id">',
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsed')]
    public function testOnDocumentParsed(string $markdown, array $config, string $expected): void
    {
        $environment = new Environment([
            'heading_permalink' => [
                'id_prefix' => 'userdocs',
                'fragment_prefix' => 'userdocs',
                'apply_id_to_heading' => true,
                ...$config,
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AttributesExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addEventListener(
            DocumentParsedEvent::class,
            [IdPrefixEventHandler::create(), 'onDocumentParsed'],
            -50
        );

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        $this->assertStringContainsString($expected, $html);
    }
}
