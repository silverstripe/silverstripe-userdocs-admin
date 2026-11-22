<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Parser\MarkdownParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\ExtensionDocMarker;

class ExtensionDocMarkerTest extends SapphireTest
{
    public static function provideGetIndex(): array
    {
        return [
            'marker' => [
                'markdown' => ExtensionDocMarker::getMarker(3),
                'expected' => '3',
            ],
            'marker with other content in the same HTML block' => [
                'markdown' => ExtensionDocMarker::getMarker(3) . ' Other content',
                'expected' => null,
            ],
            'other HTML comment' => [
                'markdown' => '<!-- some comment -->',
                'expected' => null,
            ],
            'paragraph' => [
                'markdown' => 'Some text',
                'expected' => null,
            ],
        ];
    }

    #[DataProvider('provideGetIndex')]
    public function testGetIndex(string $markdown, ?string $expected): void
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $node = (new MarkdownParser($environment))->parse($markdown)->firstChild();

        $this->assertSame($expected, ExtensionDocMarker::getIndex($node));
    }

    public function testRemoveMarkers(): void
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addEventListener(DocumentParsedEvent::class, [ExtensionDocMarker::class, 'removeMarkers']);
        $markdown = "Original\n\n" . ExtensionDocMarker::getMarker(0) . "\n\n- Item\n\n  "
            . ExtensionDocMarker::getMarker(1) . "\n\n<!-- other comment -->";

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        // Markers are removed wherever they are, but other comments aren't
        $this->assertSame(
            "<p>Original</p>\n<ul>\n<li>\n<p>Item</p>\n</li>\n</ul>\n<!-- other comment -->",
            trim($html)
        );
    }

    public static function provideWarnAboutHiddenMarkers(): array
    {
        return [
            // The original doc has a code block that is never closed, so it includes the marker
            'unclosed code block' => [
                'markdown' => "```\nsome code\n\n" . ExtensionDocMarker::getMarker(0) . "\n\nExtension content",
            ],
            // The original doc has a <pre> element that is never closed, so the HTML block includes the marker
            'unclosed HTML block' => [
                'markdown' => "<pre>\nsome code\n\n" . ExtensionDocMarker::getMarker(0) . "\n\nExtension content",
            ],
        ];
    }

    #[DataProvider('provideWarnAboutHiddenMarkers')]
    public function testWarnAboutHiddenMarkers(string $markdown): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                "Could not find where the content from '/path/to/extension.md' starts,"
                . ' so it might be in the wrong place and its links and images might not work.'
                . ' Check the content before it for an unclosed code block or HTML block.'
            );
        Injector::inst()->registerService($logger, LoggerInterface::class);
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addEventListener(
            DocumentParsedEvent::class,
            fn (DocumentParsedEvent $event) => ExtensionDocMarker::warnAboutHiddenMarkers(
                $event,
                [0 => ['filePath' => '/path/to/extension.md']]
            )
        );

        (new MarkdownConverter($environment))->convert($markdown);
    }
}
