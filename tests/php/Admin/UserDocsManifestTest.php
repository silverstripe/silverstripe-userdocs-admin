<?php

namespace SilverStripe\UserDocs\Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleManifest;
use SilverStripe\Core\Path;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class UserDocsManifestTest extends SapphireTest
{
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
        $docData = $this->getDocsManifest()->getState()['en'];
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
            'parentSlug' => null,
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
        $docData = $this->getDocsManifest()->getState()['en'];

        $this->assertSame('Title only', $docData['title_only']['title']);
        $this->assertFalse($docData['title_only']['hasContent']);
    }

    public function testFileNameToTitle(): void
    {
        $titles = array_column($this->getDocsManifest()->getState()['fr'], 'title', 'fileName');

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
        $this->assertEqualsCanonicalizing(['en', 'fr', 'fr_CA'], array_keys($this->getDocsManifest()->getState()));
    }

    public function testGetLocalisedTreeDataRootOrder(): void
    {
        $tree = $this->getDocsManifest()->getLocalisedTreeData();

        $this->assertSame(['getting_started', 'no_index', 'title_only'], array_keys($tree));
    }

    public function testGetLocalisedTreeData(): void
    {
        $tree = $this->getDocsManifest()->getLocalisedTreeData();

        // Numeric prefixes first (folders before files when equal), then unprefixed by title
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

    public function testInitReusesCache(): void
    {
        (new UserDocsManifest())->init([$this->fixturePath('docs_sibling')], true);

        // The cached manifest is used even though different paths are passed in
        $manifest = new UserDocsManifest();
        $manifest->init([$this->fixturePath('docs')], true);

        $this->assertSame(['en'], array_keys($manifest->getState()));
        $this->assertSame(['sibling_section', 'sibling_section/page'], array_keys($manifest->getState()['en']));
    }

    public function testFlush(): void
    {
        (new UserDocsManifest())->init([$this->fixturePath('docs_sibling')], true);

        UserDocsManifest::flush();
        $manifest = new UserDocsManifest();
        $manifest->init([$this->fixturePath('docs')], true);

        $this->assertArrayHasKey('getting_started', $manifest->getState()['en']);
    }

    public function testInitWithInvalidCache(): void
    {
        Injector::inst()->get(CacheInterface::class . '.userdocsmanifest')->set('manifest', ['docData' => 'invalid']);

        $manifest = new UserDocsManifest();
        $manifest->init([$this->fixturePath('docs')], true);

        $this->assertArrayHasKey('getting_started', $manifest->getState()['en']);
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

    private function fixturePath(string $path): string
    {
        return Path::join(dirname(__DIR__), 'fixtures', $path);
    }
}
