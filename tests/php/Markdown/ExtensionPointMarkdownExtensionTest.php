<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Markdown\ExtensionDocMarker;
use SilverStripe\UserDocs\Markdown\ExtensionPointMarkdownExtension;
use SilverStripe\UserDocs\Markdown\TableOfContentsEventHandler;

class ExtensionPointMarkdownExtensionTest extends SapphireTest
{
    private const ORIGINAL = "Before\n\n<!-- userdocs-extension-point: middle -->\n\nAfter";

    private const HEADINGS = "## A\n\nA text\n\n### Sub\n\nSub text\n\n## B\n\nB text";

    private const HEADINGS_HTML_A = "<h2 id=\"prefix-a\">A</h2>\n<p>A text</p>";

    private const HEADINGS_HTML_SUB = "<h3 id=\"prefix-sub\">Sub</h3>\n<p>Sub text</p>";

    private const HEADINGS_HTML_B = "<h2 id=\"prefix-b\">B</h2>\n<p>B text</p>";

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
                'extensions' => ["<!-- userdocs-insert-at: middle -->\n\nInserted"],
                'expected' => "<p>Before</p>\n<p>Inserted</p>\n<p>After</p>",
            ],
            'content before the first marker is added to the end' => [
                'original' => static::ORIGINAL,
                'extensions' => ["Appended\n\n<!-- userdocs-insert-at: middle -->\n\nInserted"],
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
                    "<!-- userdocs-insert-at: two -->\n\nSecond\n\n<!-- userdocs-insert-at: one -->\n\nFirst",
                ],
                'expected' => "<p>A</p>\n<p>First</p>\n<p>B</p>\n<p>Second</p>\n<p>C</p>",
            ],
            'several extensions at one extension point keep their order' => [
                'original' => static::ORIGINAL,
                'extensions' => [
                    "<!-- userdocs-insert-at: middle -->\n\nFirst extension",
                    "<!-- userdocs-insert-at: middle -->\n\nSecond extension",
                ],
                'expected' => "<p>Before</p>\n<p>First extension</p>\n<p>Second extension</p>\n<p>After</p>",
            ],
            // The original doc can be replaced by a doc with different extension points
            'content for a missing extension point is removed' => [
                'original' => static::ORIGINAL,
                'extensions' => [
                    "<!-- userdocs-insert-at: missing -->\n\nRemoved\n\n<!-- userdocs-insert-at: middle -->",
                ],
                'expected' => "<p>Before</p>\n<p>After</p>",
            ],
            'extension point inside a list' => [
                'original' => "- Item\n\n  <!-- userdocs-extension-point: middle -->",
                'extensions' => ["<!-- userdocs-insert-at: middle -->\n\nInserted"],
                'expected' => "<ul>\n<li>\n<p>Item</p>\n<p>Inserted</p>\n</li>\n</ul>",
            ],
            'markers without blank lines' => [
                'original' => "Before\n<!-- userdocs-extension-point: middle -->\nAfter",
                'extensions' => ["<!-- userdocs-insert-at: middle -->\nInserted"],
                'expected' => "<p>Before</p>\n<p>Inserted</p>\n<p>After</p>",
            ],
            'markers with different whitespace' => [
                'original' => "Before\n\n<!--userdocs-extension-point:middle-->\n\nAfter",
                'extensions' => ["<!--   userdocs-insert-at:   middle   -->\n\nInserted"],
                'expected' => "<p>Before</p>\n<p>Inserted</p>\n<p>After</p>",
            ],
            'markers in code are not markers' => [
                'original' => "```\n<!-- userdocs-extension-point: middle -->\n```",
                'extensions' => ["<!-- userdocs-insert-at: middle -->\n\nRemoved"],
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

    public static function provideOnDocumentParsedHeadings(): array
    {
        $a = static::HEADINGS_HTML_A;
        $sub = static::HEADINGS_HTML_SUB;
        $b = static::HEADINGS_HTML_B;
        return [
            'content before a heading' => [
                'original' => static::HEADINGS,
                'extensions' => ["<!-- userdocs-insert-before: #b -->\n\nInserted"],
                'expected' => "$a\n$sub\n<p>Inserted</p>\n$b",
            ],
            'content directly after a heading' => [
                'original' => static::HEADINGS,
                'extensions' => ["<!-- userdocs-insert-after: #a -->\n\nInserted"],
                'expected' => "<h2 id=\"prefix-a\">A</h2>\n<p>Inserted</p>\n<p>A text</p>\n$sub\n$b",
            ],
            'content at the end of a section goes after its subsections' => [
                'original' => static::HEADINGS,
                'extensions' => ["<!-- userdocs-insert-end: #a -->\n\nInserted"],
                'expected' => "$a\n$sub\n<p>Inserted</p>\n$b",
            ],
            'content at the end of the last section goes before content added to the end' => [
                'original' => static::HEADINGS,
                'extensions' => ["Appended\n\n<!-- userdocs-insert-end: #b -->\n\nInserted"],
                'expected' => "$a\n$sub\n$b\n<p>Inserted</p>\n<p>Appended</p>",
            ],
            'content at the same place between two sections' => [
                'original' => static::HEADINGS,
                'extensions' => [
                    "<!-- userdocs-insert-before: #b -->\n\nBefore B\n\n<!-- userdocs-insert-end: #a -->\n\nEnd A\n\n"
                        . "<!-- userdocs-insert-end: #sub -->\n\nEnd Sub",
                ],
                'expected' => "$a\n$sub\n<p>End Sub</p>\n<p>End A</p>\n<p>Before B</p>\n$b",
            ],
            'several extensions at one heading keep their order' => [
                'original' => static::HEADINGS,
                'extensions' => [
                    "<!-- userdocs-insert-after: #a -->\n\nFirst extension",
                    "<!-- userdocs-insert-after: #a -->\n\nSecond extension",
                ],
                'expected' => "<h2 id=\"prefix-a\">A</h2>\n<p>First extension</p>\n<p>Second extension</p>\n"
                    . "<p>A text</p>\n$sub\n$b",
            ],
            'heading with a custom ID' => [
                'original' => "## A {#custom}\n\nA text",
                'extensions' => ["<!-- userdocs-insert-after: #custom -->\n\nInserted"],
                'expected' => "<h2 id=\"prefix-custom\">A</h2>\n<p>Inserted</p>\n<p>A text</p>",
            ],
            'heading inside a list' => [
                'original' => "- Item\n\n  ## A\n\n  A text\n\nAfter the list",
                'extensions' => ["<!-- userdocs-insert-end: #a -->\n\nInserted"],
                'expected' => "<ul>\n<li>\n<p>Item</p>\n<h2 id=\"prefix-a\">A</h2>\n<p>A text</p>\n<p>Inserted</p>\n"
                    . "</li>\n</ul>\n<p>After the list</p>",
            ],
            // The original doc can be replaced by a doc with different headings
            'content for a missing heading is removed' => [
                'original' => static::HEADINGS,
                'extensions' => ["<!-- userdocs-insert-after: #missing -->\n\nRemoved"],
                'expected' => "$a\n$sub\n$b",
            ],
            'headings in extensions are not used' => [
                'original' => 'Original',
                'extensions' => ["## Extension\n\n<!-- userdocs-insert-after: #extension -->\n\nRemoved"],
                'expected' => "<p>Original</p>\n<h2 id=\"prefix-extension\">Extension</h2>",
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsedHeadings')]
    public function testOnDocumentParsedHeadings(string $original, array $extensions, string $expected): void
    {
        $html = $this->convert($original, $extensions);

        $this->assertSame($expected, trim($html));
        $this->assertSame([], $this->warnings);
    }

    public function testOnDocumentParsedHeadingsWithoutIdPrefix(): void
    {
        $html = $this->convert(
            "## A {#custom}\n\nA text",
            ["<!-- userdocs-insert-after: #custom -->\n\nInserted"],
            hasHeadingPermalinks: false
        );

        $this->assertSame("<h2 id=\"custom\">A</h2>\n<p>Inserted</p>\n<p>A text</p>", trim($html));
        $this->assertSame([], $this->warnings);
    }

    public function testOnDocumentParsedInvalidHeadingId(): void
    {
        $html = $this->convert(static::HEADINGS, ["<!-- userdocs-insert-after: a -->\n\nRemoved"]);

        // Content after an invalid "insert" marker isn't shown
        $expected = static::HEADINGS_HTML_A . "\n" . static::HEADINGS_HTML_SUB . "\n" . static::HEADINGS_HTML_B;
        $this->assertSame($expected, trim($html));
        $this->assertSame([
            'Documentation at /docs/extension0.md has "userdocs-insert-after: a",'
            . ' which must have a heading ID that starts with "#"',
        ], $this->warnings);
    }

    public function testOnDocumentParsedDuplicateHeadingId(): void
    {
        $markdown = "## A {#same}\n\nA text\n\n## B {#same}\n\nB text";

        $html = $this->convert($markdown, ["<!-- userdocs-insert-after: #same -->\n\nInserted"]);

        // The first heading with the ID is used
        $this->assertSame(
            "<h2 id=\"prefix-same\">A</h2>\n<p>Inserted</p>\n<p>A text</p>\n"
                . "<h2 id=\"prefix-same\">B</h2>\n<p>B text</p>",
            trim($html)
        );
        $this->assertSame(
            ['Documentation at /docs/page.md has more than one heading with the ID "same"'],
            $this->warnings
        );
    }

    public function testOnDocumentParsedDuplicateHeadingIdNotUsed(): void
    {
        $markdown = "## A {#same}\n\n## B {#same}\n\n## C";

        $this->convert($markdown, ["<!-- userdocs-insert-after: #c -->\n\nInserted"]);

        // Headings that no extension uses don't need unique IDs for extensions to work
        $this->assertSame([], $this->warnings);
    }

    public function testOnDocumentParsedExtensionPointInExtension(): void
    {
        $html = $this->convert('Original', [
            "First\n\n<!-- userdocs-extension-point: middle -->",
            "<!-- userdocs-insert-at: middle -->\n\nRemoved",
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

        $html = $this->convert($markdown, ["<!-- userdocs-insert-at: middle -->\n\nInserted"]);

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

        $html = $this->convert($markdown, ["<!-- userdocs-insert-at: My Point -->\n\nRemoved"]);

        // Content after an invalid "userdocs-insert-at" marker isn't shown
        $this->assertSame("<p>Before</p>\n<p>After</p>", trim($html));
        $nameRule = 'which can only have lowercase letters, numbers, "-" and "_"';
        $this->assertSame([
            "Documentation at /docs/page.md has the extension point name \"My Point\", $nameRule",
            "Documentation at /docs/extension0.md has the extension point name \"My Point\", $nameRule",
        ], $this->warnings);
    }

    public function testOnDocumentParsedInvalidNameWithoutWarnings(): void
    {
        $markdown = "Before\n\n<!-- userdocs-extension-point: My Point -->\n\nAfter";

        $html = $this->convert($markdown, ["<!-- userdocs-insert-at: My Point -->\n\nRemoved"], logWarnings: false);

        // The content is still handled the same way
        $this->assertSame("<p>Before</p>\n<p>After</p>", trim($html));
        $this->assertSame([], $this->warnings);
    }

    public function testOnDocumentParsedNestedInsertMarker(): void
    {
        $html = $this->convert(static::ORIGINAL, [
            "Appended\n\n- Item\n\n  <!-- userdocs-insert-at: middle -->\n\n  Nested\n\nAfter the list\n\n"
                . "<!-- userdocs-insert-at: middle -->\n\nInserted",
        ]);

        // The marker isn't at the top level, so the list and the content up to the next marker aren't shown
        $this->assertSame("<p>Before</p>\n<p>Inserted</p>\n<p>After</p>\n<p>Appended</p>", trim($html));
        $this->assertSame([
            'Documentation at /docs/extension0.md has "userdocs-insert-at: middle" inside other content such as a list,'
            . ' so that content and the content after it is not shown',
        ], $this->warnings);
    }

    public function testOnDocumentParsedInsertMarkerInOriginal(): void
    {
        $html = $this->convert("Before\n\n<!-- userdocs-insert-at: middle -->\n\nAfter", []);

        $this->assertSame("<p>Before</p>\n<p>After</p>", trim($html));
        $this->assertSame(
            ['Documentation at /docs/page.md has "userdocs-insert-at: middle", but it isn\'t an extension'],
            $this->warnings
        );
    }

    public static function provideOnDocumentParsedLang(): array
    {
        $wrapperStart = '<div class="userdocs-extension-content" lang="fr">';
        return [
            'same locale is not wrapped' => [
                'original' => static::ORIGINAL,
                'extensions' => ["Appended\n\n<!-- userdocs-insert-at: middle -->\n\nInserted"],
                'extensionLocales' => ['en'],
                'expected' => "<p>Before</p>\n<p>Inserted</p>\n<p>After</p>\n<p>Appended</p>",
            ],
            'extension without a locale is not wrapped' => [
                'original' => static::ORIGINAL,
                'extensions' => ['Appended'],
                'extensionLocales' => [],
                'expected' => "<p>Before</p>\n<p>After</p>\n<p>Appended</p>",
            ],
            'content added to the end' => [
                'original' => static::ORIGINAL,
                'extensions' => ["Appended\n\nMore"],
                'extensionLocales' => ['fr'],
                'expected' => "<p>Before</p>\n<p>After</p>\n$wrapperStart\n<p>Appended</p>\n<p>More</p>\n</div>",
            ],
            'each chunk is wrapped where it goes' => [
                'original' => static::ORIGINAL,
                'extensions' => ["Appended\n\n<!-- userdocs-insert-at: middle -->\n\nInserted"],
                'extensionLocales' => ['fr'],
                'expected' => "<p>Before</p>\n$wrapperStart\n<p>Inserted</p>\n</div>\n<p>After</p>\n"
                    . "$wrapperStart\n<p>Appended</p>\n</div>",
            ],
            'content near a heading' => [
                'original' => "## A\n\nA text",
                'extensions' => ["<!-- userdocs-insert-after: #a -->\n\nInserted"],
                'extensionLocales' => ['fr'],
                'expected' => "<h2 id=\"prefix-a\">A</h2>\n$wrapperStart\n<p>Inserted</p>\n</div>\n<p>A text</p>",
            ],
            'each extension has its own language' => [
                'original' => static::ORIGINAL,
                'extensions' => ['French', 'English', 'Canadian French'],
                'extensionLocales' => ['fr', 'en', 'fr_CA'],
                'expected' => "<p>Before</p>\n<p>After</p>\n$wrapperStart\n<p>French</p>\n</div>\n<p>English</p>\n"
                    . "<div class=\"userdocs-extension-content\" lang=\"fr-CA\">\n<p>Canadian French</p>\n</div>",
            ],
            'removed content is not wrapped' => [
                'original' => static::ORIGINAL,
                'extensions' => ["<!-- userdocs-insert-at: missing -->\n\nRemoved"],
                'extensionLocales' => ['fr'],
                'expected' => "<p>Before</p>\n<p>After</p>",
            ],
            'paragraphs in a tight list are in a span' => [
                'original' => "- Item\n  <!-- userdocs-extension-point: middle -->\n- Other",
                'extensions' => ["<!-- userdocs-insert-at: middle -->\n\nInserted\n\nMore"],
                'extensionLocales' => ['fr'],
                'expected' => "<ul>\n<li>Item\n<span class=\"userdocs-extension-content\" lang=\"fr\">"
                    . "Inserted\nMore</span>\n</li>\n<li>Other</li>\n</ul>",
            ],
            'other content in a tight list is in a div' => [
                'original' => "- Item\n  <!-- userdocs-extension-point: middle -->\n- Other",
                'extensions' => ["<!-- userdocs-insert-at: middle -->\n\nInserted\n\n- Nested"],
                'extensionLocales' => ['fr'],
                'expected' => "<ul>\n<li>Item\n$wrapperStart\nInserted\n<ul>\n<li>Nested</li>\n</ul>\n</div>"
                    . "\n</li>\n<li>Other</li>\n</ul>",
            ],
            'paragraphs in a loose list are in a div' => [
                'original' => "- Item\n\n  <!-- userdocs-extension-point: middle -->",
                'extensions' => ["<!-- userdocs-insert-at: middle -->\n\nInserted"],
                'extensionLocales' => ['fr'],
                'expected' => "<ul>\n<li>\n<p>Item</p>\n$wrapperStart\n<p>Inserted</p>\n</div>\n</li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideOnDocumentParsedLang')]
    public function testOnDocumentParsedLang(
        string $original,
        array $extensions,
        array $extensionLocales,
        string $expected
    ): void {
        $html = $this->convert($original, $extensions, docLocale: 'en', extensionLocales: $extensionLocales);

        $this->assertSame($expected, trim($html));
        $this->assertSame([], $this->warnings);
    }

    public function testAddLangToTableOfContents(): void
    {
        $html = $this->convert(
            "## Original\n\nText",
            ['## French', '## English'],
            docLocale: 'en',
            extensionLocales: ['fr', 'en'],
            hasTableOfContents: true
        );

        $this->assertStringContainsString('<a href="#prefix-original">Original</a>', $html);
        $this->assertStringContainsString('<a lang="fr" href="#prefix-french">French</a>', $html);
        $this->assertStringContainsString('<a href="#prefix-english">English</a>', $html);
    }

    public function testWrapContentAroundTableOfContents(): void
    {
        $html = $this->convert(
            "Intro\n\n<!-- userdocs-extension-point: middle -->",
            ["<!-- userdocs-insert-at: middle -->\n\n# Titre\n\nTexte\n\n## Section"],
            docLocale: 'en',
            extensionLocales: ['fr'],
            hasTableOfContents: true
        );

        // The table of contents goes before the first h2, so it splits the French content into two blocks
        preg_match_all('/^<(\/?div|h[12]|p|ul)[\s>]/m', $html, $matches);
        $this->assertSame(['p', 'div', 'h1', 'p', '/div', 'ul', 'div', 'h2', '/div'], $matches[1]);
        $this->assertSame(2, substr_count($html, '<div class="userdocs-extension-content" lang="fr">'));
    }

    /**
     * Convert the original markdown with the content of each extension appended, as UserDocsAdmin does.
     *
     * @param string[] $extensions The markdown content of each extension doc
     * @param string[] $extensionLocales The locale of each extension doc, keyed the same as $extensions
     */
    private function convert(
        string $original,
        array $extensions,
        bool $hasHeadingPermalinks = true,
        bool $logWarnings = true,
        ?string $docLocale = null,
        array $extensionLocales = [],
        bool $hasTableOfContents = false
    ): string {
        $markdown = $original;
        $extensionData = [];
        foreach ($extensions as $i => $extension) {
            $markdown .= "\n\n" . ExtensionDocMarker::getMarker($i) . "\n\n" . $extension;
            $extensionData[$i] = ['filePath' => "/docs/extension$i.md"];
            if (isset($extensionLocales[$i])) {
                $extensionData[$i]['locale'] = $extensionLocales[$i];
            }
        }
        $docData = ['filePath' => '/docs/page.md'];
        if ($docLocale !== null) {
            $docData['locale'] = $docLocale;
        }
        $config = [];
        if ($hasHeadingPermalinks) {
            $config['heading_permalink'] = [
                'apply_id_to_heading' => true,
                'id_prefix' => 'prefix',
                'fragment_prefix' => 'prefix',
                // The table of contents only finds headings that have a permalink
                'insert' => $hasTableOfContents ? 'after' : 'none',
            ];
        }
        if ($hasTableOfContents) {
            $config['table_of_contents'] = [
                'position' => 'placeholder',
                'placeholder' => '[TOC]',
                'min_heading_level' => 2,
            ];
        }
        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AttributesExtension());
        if ($hasHeadingPermalinks) {
            $environment->addExtension(new HeadingPermalinkExtension());
        }
        if ($hasTableOfContents) {
            $environment->addExtension(new TableOfContentsExtension());
            // Place the table of contents as MarkdownEnvironmentFactory does
            $environment->addEventListener(
                DocumentParsedEvent::class,
                [TableOfContentsEventHandler::create(), 'onDocumentParsed'],
                -140
            );
        }
        $environment->addExtension(ExtensionPointMarkdownExtension::create($docData, $extensionData, $logWarnings));
        // Section markers aren't removed by the extension, so remove them as UserDocsAdmin does
        $environment->addEventListener(DocumentParsedEvent::class, [ExtensionDocMarker::class, 'removeMarkers'], -200);
        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
    }
}
