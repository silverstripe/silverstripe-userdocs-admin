<?php

namespace SilverStripe\UserDocs\Tests\Markdown;

use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleManifest;
use SilverStripe\Core\Path;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Markdown\ExtensionDocAssembler;
use SilverStripe\UserDocs\Markdown\RelatedDocsExtension;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class RelatedDocsExtensionTest extends SapphireTest
{
    protected function setUp(): void
    {
        parent::setUp();
        // Keep the real site's manifest cache out of it
        Injector::inst()->registerService(
            new Psr16Cache(new ArrayAdapter()),
            CacheInterface::class . '.userdocsmanifest'
        );
        i18n::config()->set('default_locale', 'en_US');
        i18n::set_locale('en_US');
    }

    public static function provideAddRelatedDocs(): array
    {
        return [
            'parent class doc, parent doc, child docs, and other docs for the same class' => [
                'slug' => 'pages/specific',
                // "pages/specific/deeper/grand" names the same class, but it's a descendant
                'expected' => [
                    'pages/generic',
                    'pages',
                    'pages/specific/sub',
                    'pages/specific/also_child',
                    'pages/specific/deeper',
                    'tools/child_tool',
                    'tools/off',
                ],
            ],
            'ancestors that name the same class are left out, except the parent doc' => [
                'slug' => 'pages/specific/deeper/grand',
                'expected' => [
                    'pages/generic',
                    'pages/specific/deeper',
                    'pages/specific/also_child',
                    'tools/child_tool',
                    'tools/off',
                ],
            ],
            'class without a parent class doc' => [
                'slug' => 'pages',
                'expected' => [
                    'pages/generic',
                    'pages/specific',
                ],
            ],
            'parent doc only' => [
                'slug' => 'pages/specific/sub',
                'expected' => [
                    'pages/specific',
                ],
            ],
            'parent doc with a slug of 0' => [
                'slug' => '0/child',
                'expected' => [
                    '0',
                ],
            ],
        ];
    }

    #[DataProvider('provideAddRelatedDocs')]
    public function testAddRelatedDocs(string $slug, array $expected): void
    {
        $this->useDocs([$this->fixturePath('related_docs')]);

        $html = $this->renderDoc($slug);

        $this->assertSame($expected, $this->getRelatedLinks($html));
    }

    public static function provideAddRelatedDocsWithoutLinks(): array
    {
        return [
            'parent doc without content' => [
                'slug' => 'no_content/child',
            ],
            'turned off in the frontmatter' => [
                'slug' => 'tools/off',
            ],
        ];
    }

    #[DataProvider('provideAddRelatedDocsWithoutLinks')]
    public function testAddRelatedDocsWithoutLinks(string $slug): void
    {
        $this->useDocs([$this->fixturePath('related_docs')]);

        $html = $this->renderDoc($slug);

        $this->assertStringNotContainsString('related-docs', $html);
        $this->assertStringNotContainsString('<ul>', $html);
    }

    public function testAddRelatedDocsHeading(): void
    {
        $this->useDocs([$this->fixturePath('related_docs')]);

        $html = $this->renderDoc('pages/specific');

        // The heading gets its ID from its text, with the prefix of other heading IDs
        $this->assertStringContainsString('<h2 id="userdocs-related-docs">Related docs', $html);
        // The related docs heading would be the only heading in the table of contents, so there isn't one
        $this->assertStringNotContainsString('table-of-contents', $html);
    }

    public function testAddRelatedDocsHeadingInDocWithSameHeading(): void
    {
        $this->useDocs([$this->fixturePath('related_docs')]);

        $html = $this->renderDoc('own_heading');

        // The added heading keeps its fixed ID, so the doc's own "Related docs" heading gets a different ID
        preg_match_all('#<h2 id="([^"]+)"#', $html, $matches);
        $this->assertSame(['userdocs-related-docs-1', 'userdocs-related-docs'], $matches[1]);
        $this->assertSame(['own_heading/child'], $this->getRelatedLinks($html));
        // Both headings are in the table of contents
        preg_match('#<ul class="table-of-contents">.*?</ul>#s', $html, $tableOfContents);
        $this->assertStringContainsString('href="#userdocs-related-docs"', $tableOfContents[0] ?? '');
        $this->assertStringContainsString('href="#userdocs-related-docs-1"', $tableOfContents[0] ?? '');
    }

    public function testAddRelatedDocsLinkLanguage(): void
    {
        i18n::set_locale('fr_FR');
        $this->useDocs([$this->fixturePath('related_docs')]);

        $html = $this->renderDoc('pages/specific');

        // Only the link to the French doc has a different language from the English doc it's in
        $this->assertSame(1, substr_count($html, ' lang="'));
        $this->assertStringContainsString('<a lang="fr" href="/tools/child_tool">Outil enfant</a>', $html);
        $this->assertStringContainsString('>Related docs', $html);
    }

    public static function provideAddRelatedDocsWithExtensionContent(): array
    {
        return [
            'extension content is moved into the doc' => [
                'slug' => 'section/moved',
                'expectedContent' => ['Intro.', 'Inserted content.', 'Original content.'],
                'expectedLinks' => ['section', 'section/removed'],
            ],
            'extension content is removed because its place is missing' => [
                'slug' => 'section/removed',
                'expectedContent' => ['Original content.'],
                'expectedLinks' => ['section', 'section/moved'],
            ],
        ];
    }

    #[DataProvider('provideAddRelatedDocsWithExtensionContent')]
    public function testAddRelatedDocsWithExtensionContent(
        string $slug,
        array $expectedContent,
        array $expectedLinks
    ): void {
        $modulePaths = [
            $this->fixturePath('related_docs_extensions/module_page'),
            $this->fixturePath('related_docs_extensions/module_extension'),
        ];
        $moduleManifest = new ModuleManifest(BASE_PATH);
        foreach ($modulePaths as $modulePath) {
            $moduleManifest->addModule($modulePath);
        }
        ModuleLoader::inst()->pushManifest($moduleManifest);
        try {
            $this->useDocs(array_map(fn (string $path) => Path::join($path, 'docs'), $modulePaths));
            $html = $this->renderDoc($slug);
        } finally {
            ModuleLoader::inst()->popManifest();
        }

        // The related docs come after all other content, even though they're added after the extension content
        $previousPosition = -1;
        foreach ($expectedContent as $content) {
            $position = strpos($html, $content);
            $this->assertGreaterThan($previousPosition, $position, $content);
            $previousPosition = $position;
        }
        $this->assertGreaterThan($previousPosition, strpos($html, '<h2 id="userdocs-related-docs"'));
        $this->assertSame($expectedLinks, $this->getRelatedLinks($html));
    }

    /**
     * Build the manifest from the given documentation roots and use it as the manifest singleton.
     *
     * @param string[] $docRoots
     */
    private function useDocs(array $docRoots): void
    {
        $manifest = new UserDocsManifest();
        $manifest->regenerate($docRoots, true);
        Injector::inst()->registerService($manifest, UserDocsManifest::class);
    }

    /**
     * Render a doc the same way as UserDocsAdmin, with only the extensions that change its content.
     */
    private function renderDoc(string $slug): string
    {
        $docDatum = UserDocsManifest::singleton()->getLocalisedDocData()[$slug];
        $assembler = ExtensionDocAssembler::singleton();
        /** @var MarkdownConverter $converter */
        $converter = Injector::inst()->create(MarkdownConverter::class . '.userdocs');
        $environment = $converter->getEnvironment();
        $assembler->addToEnvironment($environment, $docDatum, false);
        $environment->addExtension(RelatedDocsExtension::create($docDatum));
        return $converter->convert($assembler->getMarkdown($docDatum))->getContent();
    }

    /**
     * Get the slugs that the links after the related docs heading go to.
     *
     * @return string[]
     */
    private function getRelatedLinks(string $html): array
    {
        $pattern = '#<h2 id="userdocs-related-docs(?:-\d+)?">[^<]*<a[^>]*>[^<]*</a></h2>\s*<ul>(.*?)</ul>#s';
        if (!preg_match($pattern, $html, $matches)) {
            return [];
        }
        preg_match_all('#href="/([^"]+)"#', $matches[1], $links);
        return $links[1];
    }

    private function fixturePath(string $path): string
    {
        return Path::join(dirname(__DIR__), 'fixtures', $path);
    }
}
