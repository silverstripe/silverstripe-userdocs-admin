<?php

namespace SilverStripe\UserDocs\Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionProperty;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleManifest;
use SilverStripe\Core\Path;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Filesystem\Path as FilesystemPath;

class UserDocsManifestTest extends SapphireTest
{
    private const MODULE = 'silverstripe/userdocs-admin';

    /**
     * Manifest built from the main fixture root. Building it scans the whole project,
     * so it's only done once and shared by tests that don't change how it's built.
     */
    private static ?UserDocsManifest $docsManifest = null;

    private static ?UserDocsManifest $localisationManifest = null;

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

    public function testHandleFile(): void
    {
        $docData = $this->getRawDocData($this->getDocsManifest())[static::MODULE]['en'];
        $fixturePath = $this->fixturePath('docs/en/01_getting_started');

        $this->assertSame([
            'order' => 1,
            'frontMatter' => ['title' => 'Start here'],
            'title' => 'Start here',
            'isIndex' => true,
            'hasContent' => true,
            'fileName' => 'index.md',
            'filePath' => "$fixturePath/index.md",
            'module' => 'silverstripe/userdocs-admin',
            'locale' => 'en',
            'slug' => 'getting_started',
            'parentSlug' => '.',
            'rootPathInModule' => 'tests/php/fixtures/docs',
            'pathFromLocale' => '01_getting_started/index.md',
        ], $docData['getting_started']);

        $this->assertSame([
            'order' => 1,
            'frontMatter' => null,
            'title' => 'First page',
            'isIndex' => false,
            'hasContent' => true,
            'fileName' => '01_first_page.md',
            'filePath' => "$fixturePath/01_first_page.md",
            'module' => 'silverstripe/userdocs-admin',
            'locale' => 'en',
            'slug' => 'getting_started/first_page',
            'parentSlug' => 'getting_started',
            'rootPathInModule' => 'tests/php/fixtures/docs',
            'pathFromLocale' => '01_getting_started/01_first_page.md',
        ], $docData['getting_started/first_page']);

        $this->assertSame([
            'order' => 3,
            'frontMatter' => null,
            'title' => 'Nested section',
            'isIndex' => true,
            'hasContent' => true,
            'fileName' => 'index.md',
            'filePath' => "$fixturePath/03_nested_section/index.md",
            'module' => 'silverstripe/userdocs-admin',
            'locale' => 'en',
            'slug' => 'getting_started/nested_section',
            'parentSlug' => 'getting_started',
            'rootPathInModule' => 'tests/php/fixtures/docs',
            'pathFromLocale' => '01_getting_started/03_nested_section/index.md',
        ], $docData['getting_started/nested_section']);
    }

    public function testHandleFileWithRootPathNamedLikeLocale(): void
    {
        $data = [];
        (new UserDocsManifest())->handleFile(
            $data,
            '01_page.md',
            $this->fixturePath('locale_name_in_root/fr/userhelp/en/01_section/01_page.md'),
            $this->fixturePath('locale_name_in_root/fr/userhelp')
        );

        $docDatum = $data['silverstripe/userdocs-admin']['en']['section/page'];
        $this->assertSame('tests/php/fixtures/locale_name_in_root/fr/userhelp', $docDatum['rootPathInModule']);
        $this->assertSame('01_section/01_page.md', $docDatum['pathFromLocale']);
    }

    public function testHandleFileInModuleWithDifferentPath(): void
    {
        // Like a module that is the project root, which is how CI runs this module's tests
        $manifest = new ModuleManifest(BASE_PATH);
        $manifest->addModule($this->fixturePath('renamed_module'));
        ModuleLoader::inst()->pushManifest($manifest);
        $data = [];
        try {
            (new UserDocsManifest())->handleFile(
                $data,
                '01_page.md',
                $this->fixturePath('renamed_module/docs/en/01_section/01_page.md'),
                $this->fixturePath('renamed_module/docs')
            );
        } finally {
            ModuleLoader::inst()->popManifest();
        }

        $docDatum = $data['test/renamed-module']['en']['section/page'];
        $this->assertSame('docs', $docDatum['rootPathInModule']);
        $this->assertSame('01_section/01_page.md', $docDatum['pathFromLocale']);
    }

    public function testHandleFileWithFrontMatterOnly(): void
    {
        $docData = $this->getRawDocData($this->getDocsManifest())[static::MODULE]['en'];

        $this->assertSame('Title only', $docData['title_only']['title']);
        $this->assertFalse($docData['title_only']['hasContent']);
    }

    public function testHandleFileWithBlankTitle(): void
    {
        $manifest = new UserDocsManifest();
        $manifest->regenerate([$this->fixturePath('blank_title')], true);
        $titles = array_column($this->getRawDocData($manifest)[static::MODULE]['en'], 'title', 'fileName');

        $this->assertSame('Section', $titles['index.md']);
        $this->assertSame('Empty title', $titles['empty_title.md']);
        $this->assertSame('Padded title', $titles['padded_title.md']);
    }

    public function testFileNameToTitle(): void
    {
        $docData = $this->getRawDocData($this->getDocsManifest())[static::MODULE]['fr'];
        $titles = array_column($docData, 'title', 'fileName');

        $this->assertSame('Étapes suivantes', $titles['03_étapes_suivantes.md']);
        $this->assertSame('Été indien', $titles['04_ÉTÉ_INDIEN.md']);
    }

    public static function provideGetSlugForPath(): array
    {
        return [
            'file' => [
                'path' => '01_section/02_page.md',
                'isRootDir' => false,
                'expected' => 'section/page',
            ],
            'index file' => [
                'path' => '01_section/03_sub_section/index.md',
                'isRootDir' => false,
                'expected' => 'section/sub_section',
            ],
            'file without numeric prefixes' => [
                'path' => 'section/page.md',
                'isRootDir' => false,
                'expected' => 'section/page',
            ],
            'non-ASCII capitals are lowercased' => [
                'path' => '01_getting_started/04_ÉTÉ_INDIEN.md',
                'isRootDir' => false,
                'expected' => 'getting_started/été_indien',
            ],
            'index file without a directory' => [
                'path' => 'index.md',
                'isRootDir' => false,
                'expected' => '.',
            ],
            'index file in the current directory' => [
                'path' => './index.md',
                'isRootDir' => false,
                'expected' => '.',
            ],
            'decomposed accents are composed' => [
                // "e" followed by a combining acute accent
                'path' => "01_section/e\u{301}te\u{301}.md",
                'isRootDir' => false,
                'expected' => "section/\u{e9}t\u{e9}",
            ],
            'decomposed capital accents are composed and lowercased' => [
                'path' => "01_E\u{301}TE\u{301}",
                'isRootDir' => true,
                'expected' => "\u{e9}t\u{e9}",
            ],
            'file without a directory' => [
                'path' => '02_page.md',
                'isRootDir' => false,
                'expected' => 'page',
            ],
            'root directory' => [
                'path' => '01_section',
                'isRootDir' => true,
                'expected' => 'section',
            ],
            // Links to the index file of the folder the linking doc is in
            'index file without a directory' => [
                'path' => 'index.md',
                'isRootDir' => false,
                'expected' => '.',
            ],
            'index file in the current directory' => [
                'path' => './index.md',
                'isRootDir' => false,
                'expected' => '.',
            ],
            'index file in the parent directory' => [
                'path' => '../index.md',
                'isRootDir' => false,
                'expected' => '..',
            ],
        ];
    }

    #[DataProvider('provideGetSlugForPath')]
    public function testGetSlugForPath(string $path, bool $isRootDir, string $expected): void
    {
        $this->assertSame($expected, UserDocsManifest::create()->getSlugForPath($path, $isRootDir));
    }

    public function testRegenerateScansOnlyRegisteredRoot(): void
    {
        // fixtures/docs_sibling shares a prefix with fixtures/docs but isn't registered,
        // so its files must not show up (e.g. as a bogus "_sibling" locale)
        $this->assertEqualsCanonicalizing(
            ['en', 'fr', 'fr_CA'],
            array_keys($this->getRawDocData($this->getDocsManifest())[static::MODULE])
        );
    }

    public static function provideGetLocalisedDocDataSortsByLocalisedTitle(): array
    {
        return [
            // "Car" and "House"
            'English' => [
                'locale' => 'en_US',
                'expected' => ['section', 'section/car', 'section/house'],
            ],
            // "Maison" and "Voiture"
            'French' => [
                'locale' => 'fr_FR',
                'expected' => ['section', 'section/house', 'section/car'],
            ],
        ];
    }

    #[DataProvider('provideGetLocalisedDocDataSortsByLocalisedTitle')]
    public function testGetLocalisedDocDataSortsByLocalisedTitle(string $locale, array $expected): void
    {
        i18n::set_locale($locale);
        $manifest = new UserDocsManifest();
        $manifest->regenerate([$this->fixturePath('localised_sorting')], true);

        $this->assertSame($expected, array_keys($manifest->getLocalisedDocData()));
    }

    public function testGetLocalisedDocDataSortsWithLocaleRules(): void
    {
        i18n::set_locale('fr_FR');
        $manifest = new UserDocsManifest();
        $manifest->regenerate([$this->fixturePath('sorting')], true);

        // Files come before folders when neither has a prefix. Case and accents don't affect the order.
        $this->assertSame([
            'section',
            'section/apple',
            'section/banane',
            'section/étapes',
            'section/zèbre',
            'section/été',
            'section/zoo',
        ], array_keys($manifest->getLocalisedDocData()));
    }

    public function testGetLocalisedTreeDataRootOrder(): void
    {
        $tree = $this->getDocsManifest()->getLocalisedTreeData();

        $this->assertSame(['getting_started', 'no_index', 'title_only'], array_keys($tree));
    }

    public function testGetLocalisedTreeData(): void
    {
        $tree = $this->getDocsManifest()->getLocalisedTreeData();

        // Prefixed files, then unprefixed files sorted by title (from the front matter if set), then the folder
        $this->assertSame([
            'slug' => 'getting_started',
            'title' => 'Start here',
            'isIndex' => true,
            'hasContent' => true,
            'children' => [
                'first_page' => [
                    'slug' => 'getting_started/first_page',
                    'title' => 'First page',
                    'isIndex' => false,
                    'hasContent' => true,
                ],
                'second_page' => [
                    'slug' => 'getting_started/second_page',
                    'title' => 'Second page',
                    'isIndex' => false,
                    'hasContent' => true,
                ],
                'zebra' => [
                    'slug' => 'getting_started/zebra',
                    'title' => 'Aardvark',
                    'isIndex' => false,
                    'hasContent' => true,
                ],
                'apple' => [
                    'slug' => 'getting_started/apple',
                    'title' => 'Apple',
                    'isIndex' => false,
                    'hasContent' => true,
                ],
                'nested_section' => [
                    'slug' => 'getting_started/nested_section',
                    'title' => 'Nested section',
                    'isIndex' => true,
                    'hasContent' => true,
                    'children' => [
                        'child_page' => [
                            'slug' => 'getting_started/nested_section/child_page',
                            'title' => 'Child page',
                            'isIndex' => false,
                            'hasContent' => true,
                        ],
                    ],
                ],
            ],
        ], $tree['getting_started']);
    }

    public function testGetLocalisedTreeDataRootWithoutIndex(): void
    {
        $root = $this->getDocsManifest()->getLocalisedTreeData()['no_index'];

        $this->assertSame('no_index', $root['slug'] ?? null);
        $this->assertSame('No index', $root['title'] ?? null);
        $this->assertFalse($root['hasContent'] ?? null);
        $this->assertSame(['some_page'], array_keys($root['children']));
    }

    public function testGetLocalisedTreeDataWithNumericRoot(): void
    {
        $manifest = new UserDocsManifest();
        $manifest->regenerate([$this->fixturePath('numeric_root')], true);

        $this->assertSame([
            'slug' => '2024',
            'title' => '2024',
            'isIndex' => true,
            'hasContent' => true,
            'children' => [
                'release_notes' => [
                    'slug' => '2024/release_notes',
                    'title' => 'Release notes',
                    'isIndex' => false,
                    'hasContent' => true,
                ],
            ],
        ], $manifest->getLocalisedTreeData()['2024']);
    }

    public function testGetLocalisedTreeDataWithNumericRootSlugs(): void
    {
        // PHP compares numeric strings by value, so "10.0" and "1e1" are equal in a loose comparison.
        // PHP also changes the "10" slug to an int when it's used as an array key.
        $manifest = new UserDocsManifest();
        $manifest->regenerate([$this->fixturePath('numeric_roots')], true);
        $tree = $manifest->getLocalisedTreeData();

        $this->assertSame('Ten', $tree['10']['title'] ?? null);
        $this->assertSame('Ten point zero', $tree['10.0']['title'] ?? null);
        $this->assertSame('One e one', $tree['1e1']['title'] ?? null);
    }

    public static function provideGetLocalisedTreeDataSortOrder(): array
    {
        return [
            'prefixed docs and an unprefixed folder' => [
                'root' => 'section_a',
                'expected' => [
                    'section_a/file',
                    // Folders come before files with the same prefix
                    'section_a/folder',
                    'section_a/same',
                    // Files with the same prefix are sorted by title
                    'section_a/a',
                    'section_a/b',
                    'section_a/zfolder',
                ],
            ],
            'unprefixed files and a prefixed folder' => [
                'root' => 'section_b',
                'expected' => [
                    // Version numbers and numbers are compared by value
                    'section_b/1.9.0',
                    'section_b/1.10.0',
                    'section_b/9',
                    'section_b/10',
                    // Titles are not case sensitive
                    'section_b/afile',
                    'section_b/bfile',
                    // Files come before folders when only one of them has a prefix
                    'section_b/folder',
                    'section_b/cfolder',
                ],
            ],
            'titles from frontmatter' => [
                'root' => 'section_c',
                'expected' => [
                    // "Alpha" and "Beta" have the same prefix
                    'section_c/b',
                    'section_c/a',
                    // "Apple" and "Zebra"
                    'section_c/zzz',
                    'section_c/aaa',
                    // "Bee" and "Yak"
                    'section_c/zfolder',
                    'section_c/afolder',
                ],
            ],
        ];
    }

    #[DataProvider('provideGetLocalisedTreeDataSortOrder')]
    public function testGetLocalisedTreeDataSortOrder(string $root, array $expected): void
    {
        $manifest = new UserDocsManifest();
        $manifest->regenerate([$this->fixturePath('sort_order')], true);
        $children = $manifest->getLocalisedTreeData()[$root]['children'] ?? [];

        $this->assertSame($expected, array_column($children, 'slug'));
    }

    public static function provideGetLocalisedDocData(): array
    {
        return [
            'default locale' => [
                'locale' => 'en_US',
                'defaultLocale' => 'en_US',
                'expectedRootTitle' => 'Start here',
                'expectedSecondPageTitle' => 'Second page',
            ],
            'falls back to primary language' => [
                'locale' => 'fr_FR',
                'defaultLocale' => 'en_US',
                'expectedRootTitle' => 'Commencer',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
            'full locale overrides primary language' => [
                'locale' => 'fr_CA',
                'defaultLocale' => 'en_US',
                'expectedRootTitle' => 'Commencer (Canada)',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
            'falls back to default locale language' => [
                'locale' => 'de_DE',
                'defaultLocale' => 'fr_FR',
                'expectedRootTitle' => 'Commencer',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
            'falls back to full default locale' => [
                'locale' => 'de_DE',
                'defaultLocale' => 'fr_CA',
                'expectedRootTitle' => 'Commencer (Canada)',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
            'english user locale overrides non-english default locale' => [
                'locale' => 'en_NZ',
                'defaultLocale' => 'fr_FR',
                'expectedRootTitle' => 'Start here',
                'expectedSecondPageTitle' => 'Second page',
            ],
            'user locale language overrides full default locale' => [
                'locale' => 'fr_FR',
                'defaultLocale' => 'fr_CA',
                'expectedRootTitle' => 'Commencer',
                'expectedSecondPageTitle' => 'Deuxième page',
            ],
        ];
    }

    #[DataProvider('provideGetLocalisedDocData')]
    public function testGetLocalisedDocData(
        string $locale,
        string $defaultLocale,
        string $expectedRootTitle,
        string $expectedSecondPageTitle
    ): void {
        i18n::config()->set('default_locale', $defaultLocale);
        i18n::set_locale($locale);

        $docData = $this->getDocsManifest()->getLocalisedDocData();

        $this->assertSame($expectedRootTitle, $docData['getting_started']['title']);
        $this->assertSame($expectedSecondPageTitle, $docData['getting_started/second_page']['title']);
        // Pages with no translation always fall back to English
        $this->assertSame('First page', $docData['getting_started/first_page']['title']);
    }

    public function testGetLocalisedDocDataWithoutDocs(): void
    {
        // No documentation roots is the default configuration
        $manifest = new UserDocsManifest();
        $manifest->regenerate([], false);

        $this->assertSame([], $manifest->getLocalisedDocData());
        $this->assertSame([], $manifest->getLocalisedTreeData());
    }

    public static function provideEncodeSlug(): array
    {
        return [
            'plain slug' => [
                'slug' => 'section/page',
                'expected' => 'section/page',
            ],
            'characters with a meaning in URLs' => [
                'slug' => 'section/c# & 100%?',
                'expected' => 'section/c%23%20%26%20100%25%3F',
            ],
            'non-ASCII characters' => [
                'slug' => 'section/été',
                'expected' => 'section/%C3%A9t%C3%A9',
            ],
            'dots' => [
                'slug' => 'section/release.notes',
                'expected' => 'section/release%2Enotes',
            ],
            'relative path segments' => [
                'slug' => '../section/.',
                'expected' => '../section/.',
            ],
        ];
    }

    #[DataProvider('provideEncodeSlug')]
    public function testEncodeSlug(string $slug, string $expected): void
    {
        $this->assertSame($expected, UserDocsManifest::encodeSlug($slug));
    }

    public function testGetLocalisedDocDataWithTranslationWithoutRootIndex(): void
    {
        i18n::set_locale('fr_FR');
        $manifest = $this->getLocalisationManifest();

        // fr/01_section has pages but no index.md, so only en/01_section/index.md describes the root
        $docData = $manifest->getLocalisedDocData()['section'];
        $this->assertSame('Section overview', $docData['title']);
        $this->assertTrue($docData['hasContent']);
        $treeNode = $manifest->getLocalisedTreeData()['section'];
        $this->assertSame('Section overview', $treeNode['title']);
        $this->assertTrue($treeNode['hasContent']);
    }

    public function testRegenerateWithFileDirectlyUnderLocale(): void
    {
        $manifest = new UserDocsManifest();
        $fixturePath = $this->fixturePath('invalid');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'User documentation files must have at least one directory between the locale and file.'
        );

        $manifest->regenerate([$fixturePath], true);
    }

    public function testRegenerateWithPageWithoutContent(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Documentation at ' . $this->fixturePath('no_content/en/section/empty.md') . ' has no content');
        Injector::inst()->registerService($logger, LoggerInterface::class);

        (new UserDocsManifest())->regenerate([$this->fixturePath('no_content')], true);
    }

    public static function provideGetLocalisedDocDataWithModuleOverride(): array
    {
        // module_a only has English docs, module_b has English and French docs,
        // and module_d only has German docs
        return [
            'first module has priority' => [
                'locale' => 'en_US',
                'modules' => ['module_a', 'module_b'],
                'expectedModule' => 'test/module-a',
                'expectedLocale' => 'en',
            ],
            'second module has priority' => [
                'locale' => 'en_US',
                'modules' => ['module_b', 'module_a'],
                'expectedModule' => 'test/module-b',
                'expectedLocale' => 'en',
            ],
            'module with priority is used even if another module has a translation' => [
                'locale' => 'fr_FR',
                'modules' => ['module_a', 'module_b'],
                'expectedModule' => 'test/module-a',
                'expectedLocale' => 'en',
            ],
            'translation from module with priority' => [
                'locale' => 'fr_FR',
                'modules' => ['module_b', 'module_a'],
                'expectedModule' => 'test/module-b',
                'expectedLocale' => 'fr',
            ],
            'module with priority has no doc in the current locales' => [
                'locale' => 'en_US',
                'modules' => ['module_d', 'module_a'],
                'expectedModule' => 'test/module-a',
                'expectedLocale' => 'en',
            ],
        ];
    }

    #[DataProvider('provideGetLocalisedDocDataWithModuleOverride')]
    public function testGetLocalisedDocDataWithModuleOverride(
        string $locale,
        array $modules,
        string $expectedModule,
        string $expectedLocale
    ): void {
        i18n::set_locale($locale);

        $docData = $this->regenerateForModules($modules)->getLocalisedDocData();

        foreach (['section', 'section/page'] as $slug) {
            $this->assertSame($expectedModule, $docData[$slug]['module'], $slug);
            $this->assertSame($expectedLocale, $docData[$slug]['locale'], $slug);
        }
    }

    public function testGetLocalisedDocDataWithModuleOverrideRootWithoutIndex(): void
    {
        // module_c has priority but has no index.md for its root folder
        $docData = $this->regenerateForModules(['module_c', 'module_a'])->getLocalisedDocData();

        $this->assertSame('test/module-a', $docData['section']['module']);
        $this->assertTrue($docData['section']['isIndex']);
        $this->assertSame('test/module-c', $docData['section/page']['module']);
    }

    public function testGetLocalisedTreeDataWithModuleOverride(): void
    {
        // module_b has priority, so its French title is used for the French locale
        i18n::set_locale('fr_FR');

        $tree = $this->regenerateForModules(['module_b', 'module_a'])->getLocalisedTreeData();

        $this->assertSame('Section B en français', $tree['section']['title']);
        $this->assertSame(['page'], array_keys($tree['section']['children']));
    }

    public function testInitWithDocumentationRoots(): void
    {
        UserDocsManifest::config()->set('documentation_roots', [
            // module:path format
            'silverstripe/userdocs-admin:tests/php/fixtures/docs' => true,
            // explicitly excluded
            'silverstripe/userdocs-admin:tests/php/fixtures/no_content' => false,
            // list item with a path relative to the project root
            FilesystemPath::makeRelative($this->fixturePath('docs_sibling'), BASE_PATH),
        ]);

        $manifest = new UserDocsManifest();
        $manifest->init(true);

        $docData = $this->getRawDocData($manifest)[static::MODULE]['en'];
        $this->assertArrayHasKey('getting_started', $docData);
        $this->assertArrayHasKey('sibling_section', $docData);
        $this->assertArrayNotHasKey('section', $docData);
    }

    public function testInitReusesCache(): void
    {
        $this->setDocumentationRoot('docs_sibling');
        (new UserDocsManifest())->init(true);

        // The cached manifest is used even though the documentation roots changed
        $this->setDocumentationRoot('docs');
        $manifest = new UserDocsManifest();
        $manifest->init(true);

        $state = $this->getRawDocData($manifest)[static::MODULE];
        $this->assertSame(['en'], array_keys($state));
        $this->assertSame(['sibling_section', 'sibling_section/page'], array_keys($state['en']));
    }

    public function testFlush(): void
    {
        $this->setDocumentationRoot('docs_sibling');
        (new UserDocsManifest())->init(true);

        UserDocsManifest::flush();
        $this->setDocumentationRoot('docs');
        $manifest = new UserDocsManifest();
        $manifest->init(true);

        $this->assertArrayHasKey('getting_started', $this->getRawDocData($manifest)[static::MODULE]['en']);
    }

    public function testInitWithInvalidCache(): void
    {
        Injector::inst()->get(CacheInterface::class . '.userdocsmanifest')->set('manifest', ['docData' => 'invalid']);

        $this->setDocumentationRoot('docs');
        $manifest = new UserDocsManifest();
        $manifest->init(true);

        $this->assertArrayHasKey('getting_started', $this->getRawDocData($manifest)[static::MODULE]['en']);
    }

    public function testRegenerateResetsManifest(): void
    {
        $manifest = new UserDocsManifest();
        $manifest->regenerate([$this->fixturePath('docs')], true);

        // Regenerating fails part way through, so the old docs must not be left in the manifest
        try {
            $manifest->regenerate([$this->fixturePath('invalid')], true);
        } catch (InvalidArgumentException) {
        }

        $this->assertSame([], $this->getRawDocData($manifest));
    }

    /**
     * Get the doc data the manifest stores, keyed by module, then locale, then slug.
     */
    private function getRawDocData(UserDocsManifest $manifest): array
    {
        return (new ReflectionProperty(UserDocsManifest::class, 'docData'))->getValue($manifest);
    }

    private function getDocsManifest(): UserDocsManifest
    {
        if (!static::$docsManifest) {
            static::$docsManifest = new UserDocsManifest();
            static::$docsManifest->regenerate([$this->fixturePath('docs')], true);
        }
        return static::$docsManifest;
    }

    private function getLocalisationManifest(): UserDocsManifest
    {
        if (!static::$localisationManifest) {
            static::$localisationManifest = new UserDocsManifest();
            static::$localisationManifest->regenerate([$this->fixturePath('localisation')], true);
        }
        return static::$localisationManifest;
    }

    /**
     * Build a manifest from the docs of the given module_override fixture modules.
     *
     * @param string[] $modules Module directory names, from highest to lowest priority
     */
    private function regenerateForModules(array $modules): UserDocsManifest
    {
        $moduleManifest = new ModuleManifest(BASE_PATH);
        $docRoots = [];
        foreach ($modules as $module) {
            $modulePath = $this->fixturePath("module_override/$module");
            $moduleManifest->addModule($modulePath);
            $docRoots[] = Path::join($modulePath, 'docs');
        }
        ModuleLoader::inst()->pushManifest($moduleManifest);
        $manifest = new UserDocsManifest();
        try {
            $manifest->regenerate($docRoots, true);
        } finally {
            ModuleLoader::inst()->popManifest();
        }
        return $manifest;
    }

    private function setDocumentationRoot(string $fixture): void
    {
        UserDocsManifest::config()->set('documentation_roots', [
            "silverstripe/userdocs-admin:tests/php/fixtures/$fixture" => true,
        ]);
    }

    private function fixturePath(string $path): string
    {
        return Path::join(dirname(__DIR__), 'fixtures', $path);
    }
}
