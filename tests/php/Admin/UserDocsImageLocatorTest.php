<?php

namespace SilverStripe\UserDocs\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleManifest;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\UserDocs\Admin\UserDocsImageLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class UserDocsImageLocatorTest extends SapphireTest
{
    /**
     * The module has no composer.json file, so its name has no vendor
     */
    private const MODULE = 'userdocs_image_locator_test';

    private const ROOT = 'docs/userhelp';

    private Filesystem $filesystem;

    private string $tempFolder;

    private string $modulePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->tempFolder = Path::join(TEMP_PATH, 'UserDocsImageLocatorTest_' . uniqid());
        $this->modulePath = Path::join($this->tempFolder, static::MODULE);
        $png = Path::join(dirname(__DIR__), 'fixtures/docs/en/01_getting_started/images/screenshot.png');
        $imagesPath = Path::join($this->modulePath, static::ROOT, 'en/section/images');
        $this->filesystem->copy($png, Path::join($imagesPath, 'image.png'));
        $this->filesystem->copy($png, Path::join($imagesPath, 'UPPERCASE.PNG'));
        $this->filesystem->copy($png, Path::join($imagesPath, 'no_extension'));
        $this->filesystem->copy($png, Path::join($this->modulePath, 'outside.png'));
        $this->filesystem->copy($png, Path::join($this->tempFolder, 'outside_module.png'));
        $this->filesystem->dumpFile(Path::join($imagesPath, 'image.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->filesystem->dumpFile(Path::join($imagesPath, 'text.txt'), 'Some text');
        $this->filesystem->dumpFile(Path::join($imagesPath, 'text.png'), 'Some text');
        $this->filesystem->symlink(
            Path::join($this->modulePath, 'outside.png'),
            Path::join($imagesPath, 'symlink.png')
        );
        $moduleManifest = new ModuleManifest(BASE_PATH);
        $moduleManifest->addModule($this->modulePath);
        ModuleLoader::inst()->pushManifest($moduleManifest);
    }

    protected function tearDown(): void
    {
        ModuleLoader::inst()->popManifest();
        $this->filesystem->remove($this->tempFolder);
        parent::tearDown();
    }

    public function testGetFilePath(): void
    {
        $path = static::ROOT . '/en/section/images/image.png';

        $filePath = UserDocsImageLocator::create()->getFilePath(static::MODULE, static::ROOT, $path);

        $this->assertSame(realpath(Path::join($this->modulePath, $path)), $filePath);
    }

    public static function provideGetFilePathNotFound(): array
    {
        return [
            'missing file' => [
                'path' => 'docs/userhelp/en/section/images/missing.png',
            ],
            'folder' => [
                'path' => 'docs/userhelp/en/section/images',
            ],
            'file in module but outside root' => [
                'path' => 'outside.png',
            ],
            'path traversal out of root' => [
                'path' => 'docs/userhelp/../../outside.png',
            ],
            'path traversal out of module' => [
                'path' => '../outside_module.png',
            ],
            'symlink to file outside root' => [
                'path' => 'docs/userhelp/en/section/images/symlink.png',
            ],
        ];
    }

    #[DataProvider('provideGetFilePathNotFound')]
    public function testGetFilePathNotFound(string $path): void
    {
        $this->assertNull(UserDocsImageLocator::create()->getFilePath(static::MODULE, static::ROOT, $path));
    }

    public function testGetFilePathAbsolutePath(): void
    {
        // An absolute path is treated as relative to the module, so it can't find a file outside the module
        $path = Path::join($this->modulePath, static::ROOT, 'en/section/images/image.png');

        $this->assertNull(UserDocsImageLocator::create()->getFilePath(static::MODULE, static::ROOT, $path));
    }

    public function testGetFilePathUnknownModule(): void
    {
        $this->assertNull(
            UserDocsImageLocator::create()->getFilePath(
                'test/unknown-module',
                static::ROOT,
                static::ROOT . '/en/section/images/image.png'
            )
        );
    }

    public function testGetFilePathModuleShortName(): void
    {
        // The short name of a module with a vendor is the name of its folder
        $vendorModulePath = Path::join($this->tempFolder, 'vendor_module');
        $this->filesystem->dumpFile(
            Path::join($vendorModulePath, 'composer.json'),
            json_encode(['name' => 'test/vendor-module'])
        );
        $this->filesystem->copy(
            Path::join($this->modulePath, static::ROOT, 'en/section/images/image.png'),
            Path::join($vendorModulePath, static::ROOT, 'en/section/images/image.png')
        );
        $moduleManifest = new ModuleManifest(BASE_PATH);
        $moduleManifest->addModule($vendorModulePath);
        ModuleLoader::inst()->pushManifest($moduleManifest);
        $locator = UserDocsImageLocator::create();
        $path = static::ROOT . '/en/section/images/image.png';
        try {
            $this->assertNotNull($locator->getFilePath('test/vendor-module', static::ROOT, $path));
            $this->assertNull($locator->getFilePath('vendor_module', static::ROOT, $path));
        } finally {
            ModuleLoader::inst()->popManifest();
        }
    }

    public static function provideGetMimeType(): array
    {
        return [
            'PNG image' => [
                'fileName' => 'image.png',
                'expected' => 'image/png',
            ],
            'extension in uppercase' => [
                'fileName' => 'UPPERCASE.PNG',
                'expected' => 'image/png',
            ],
            'SVG image' => [
                'fileName' => 'image.svg',
                'expected' => 'image/svg+xml',
            ],
            'unsupported extension' => [
                'fileName' => 'text.txt',
                'expected' => null,
            ],
            'no extension' => [
                'fileName' => 'no_extension',
                'expected' => null,
            ],
            'content does not match extension' => [
                'fileName' => 'text.png',
                'expected' => null,
            ],
        ];
    }

    #[DataProvider('provideGetMimeType')]
    public function testGetMimeType(string $fileName, ?string $expected): void
    {
        $filePath = Path::join($this->modulePath, static::ROOT, 'en/section/images', $fileName);

        $this->assertSame($expected, UserDocsImageLocator::create()->getMimeType($filePath));
    }

    public function testGetHash(): void
    {
        $filePath = Path::join($this->modulePath, static::ROOT, 'en/section/images/text.txt');
        $locator = UserDocsImageLocator::create();
        $hash = $locator->getHash($filePath);
        $modifiedTime = filemtime($filePath);

        // Some deployments keep the modified time when the content changes
        $this->filesystem->dumpFile($filePath, 'Different text');
        touch($filePath, $modifiedTime);

        $this->assertNotSame($hash, $locator->getHash($filePath));
    }
}
