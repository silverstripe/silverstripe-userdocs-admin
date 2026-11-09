<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\ExtensionDocMarker;
use SilverStripe\UserDocs\Markdown\ExtensionPointMarkdownExtension;

class ExtensionPointMarkdownExtensionTest extends SapphireTest
{
    private const ORIGINAL = "Before\n\n<!-- userdocs-extension-point: middle -->\n\nAfter";

    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message) {
            $this->warnings[] = $message;
        });
        Injector::inst()->registerService($logger, LoggerInterface::class);
    }

    public static function provideOnDocumentParsed(): array
    {
        return [
            'content at an extension point' => [
                'original' => static::ORIGINAL,
                'extensions' => ["<!-- userdocs-extends: middle -->\n\nInserted"],
                'expected' => "<p>Before</p>\n<p>Inserted</p>\n<p>After</p>",
            ],
            'content before the first marker is added to the end' => [
                'original' => static::ORIGINAL,
                'extensions' => ["Appended\n\n<!-- userdocs-extends: middle -->\n\nInserted"],
                'expected' => "<p>Before</p>\n<p>Inserted</p>\n<p>After</p>\n<p>Appended</p>",
            ],
            'extension without markers is added to the end' => [
                'original' => static::ORIGINAL,
                'extensions' => ['Appended'],
                'expected' => "<p>Before</p>\n<p>After</p>\n<p>Appended</p>",
            ],
            'content for several extension points' => [
                'original' => "A\n\n<!-- userdocs-extension-point: one -->\n\nB\n\n"
                    . "<!-- userdocs-extension-point: two -->\n\nC",
                'extensions' => [
                    "<!-- userdocs-extends: two -->\n\nSecond\n\n<!-- userdocs-extends: one -->\n\nFirst",
                ],
                'expected' => "<p>A</p>\n<p>First</p>\n<p>B</p>\n<p>Second</p>\n<p>C</p>",
            ],
            'several extensions at one extension point keep their order' => [
                'original' => static::ORIGINAL,
                'extensions' => [
                    "<!-- userdocs-extends: middle -->\n\nFirst extension",
                    "<!-- userdocs-extends: middle -->\n\nSecond extension",
                ],
                'expected' => "<p>Before</p>\n<p>First extension</p>\n<p>Second extension</p>\n<p>After</p>",
            ],
            // The original doc can be replaced by a doc with different extension points
            'content for a missing extension point is removed' => [
                'original' => static::ORIGINAL,
                'extensions' => ["<!-- userdocs-extends: missing -->\n\nRemoved\n\n<!-- userdocs-extends: middle -->"],
                'expected' => "<p>Before</p>\n<p>After</p>",
            ],
            'extension point inside a list' => [
                'original' => "- Item\n\n  <!-- userdocs-extension-point: middle -->",
                'extensions' => ["<!-- userdocs-extends: middle -->\n\nInserted"],
                'expected' => "<ul>\n<li>\n<p>Item</p>\n<p>Inserted</p>\n</li>\n</ul>",
            ],
            'markers without blank lines' => [
                'original' => "Before\n<!-- userdocs-extension-point: middle -->\nAfter",
                'extensions' => ["<!-- userdocs-extends: middle -->\nInserted"],
                'expected' => "<p>Before</p>\n<p>Inserted</p>\n<p>After</p>",
            ],
            'markers with different whitespace' => [
                'original' => "Before\n\n<!--userdocs-extension-point:middle-->\n\nAfter",
                'extensions' => ["<!--   userdocs-extends:   middle   -->\n\nInserted"],
                'expected' => "<p>Before</p>\n<p>Inserted</p>\n<p>After</p>",
            ],
            'markers in code are not markers' => [
                'original' => "```\n<!-- userdocs-extension-point: middle -->\n```",
                'extensions' => ["<!-- userdocs-extends: middle -->\n\nRemoved"],
                'expected' => "<pre><code>&lt;!-- userdocs-extension-point: middle --&gt;\n</code></pre>",
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsed')]
    public function testOnDocumentParsed(string $original, array $extensions, string $expected): void
    {
        $html = $this->convert($original, $extensions);

        $this->assertSame($expected, trim($html));
        $this->assertSame([], $this->warnings);
    }

    public function testOnDocumentParsedExtensionPointInExtension(): void
    {
        $html = $this->convert('Original', [
            "First\n\n<!-- userdocs-extension-point: middle -->",
            "<!-- userdocs-extends: middle -->\n\nRemoved",
        ]);

        $this->assertSame("<p>Original</p>\n<p>First</p>", trim($html));
        $this->assertSame([
            'Documentation at /docs/extension0.md has the "middle" extension point,'
            . ' but only an original doc can have extension points',
        ], $this->warnings);
    }

    public function testOnDocumentParsedDuplicateExtensionPoint(): void
    {
        $markdown = static::ORIGINAL . "\n\n<!-- userdocs-extension-point: middle -->\n\nEnd";

        $html = $this->convert($markdown, ["<!-- userdocs-extends: middle -->\n\nInserted"]);

        // The first extension point with the name is used
        $this->assertSame("<p>Before</p>\n<p>Inserted</p>\n<p>After</p>\n<p>End</p>", trim($html));
        $this->assertSame(
            ['Documentation at /docs/page.md has more than one "middle" extension point'],
            $this->warnings
        );
    }

    public function testOnDocumentParsedInvalidName(): void
    {
        $markdown = "Before\n\n<!-- userdocs-extension-point: My Point -->\n\nAfter";

        $html = $this->convert($markdown, ["<!-- userdocs-extends: My Point -->\n\nRemoved"]);

        // Content after an invalid "userdocs-extends" marker isn't shown
        $this->assertSame("<p>Before</p>\n<p>After</p>", trim($html));
        $nameRule = 'which can only have lowercase letters, numbers, "-" and "_"';
        $this->assertSame([
            "Documentation at /docs/page.md has the extension point name \"My Point\", $nameRule",
            "Documentation at /docs/extension0.md has the extension point name \"My Point\", $nameRule",
        ], $this->warnings);
    }

    public function testOnDocumentParsedNestedExtendsMarker(): void
    {
        $html = $this->convert(static::ORIGINAL, [
            "Appended\n\n- Item\n\n  <!-- userdocs-extends: middle -->\n\n  Nested\n\nAfter the list\n\n"
                . "<!-- userdocs-extends: middle -->\n\nInserted",
        ]);

        // The marker isn't at the top level, so the list and the content up to the next marker aren't shown
        $this->assertSame("<p>Before</p>\n<p>Inserted</p>\n<p>After</p>\n<p>Appended</p>", trim($html));
        $this->assertSame([
            'Documentation at /docs/extension0.md has "userdocs-extends: middle" inside other content such as a list,'
            . ' so that content and the content after it is not shown',
        ], $this->warnings);
    }

    public function testOnDocumentParsedExtendsMarkerInOriginal(): void
    {
        $html = $this->convert("Before\n\n<!-- userdocs-extends: middle -->\n\nAfter", []);

        $this->assertSame("<p>Before</p>\n<p>After</p>", trim($html));
        $this->assertSame(
            ['Documentation at /docs/page.md has "userdocs-extends: middle", but it isn\'t an extension'],
            $this->warnings
        );
    }

    /**
     * Convert the original markdown with the content of each extension appended, as UserDocsAdmin does.
     *
     * @param string[] $extensions The markdown content of each extension doc
     */
    private function convert(string $original, array $extensions): string
    {
        $markdown = $original;
        $extensionData = [];
        foreach ($extensions as $i => $extension) {
            $markdown .= "\n\n" . ExtensionDocMarker::getMarker($i) . "\n\n" . $extension;
            $extensionData[$i] = ['filePath' => "/docs/extension$i.md"];
        }
        $docData = ['filePath' => '/docs/page.md'];
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(ExtensionPointMarkdownExtension::create($docData, $extensionData));
        // Section markers aren't removed by the extension, so remove them as UserDocsAdmin does
        $environment->addEventListener(DocumentParsedEvent::class, [ExtensionDocMarker::class, 'removeMarkers'], -200);
        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
    }
}
