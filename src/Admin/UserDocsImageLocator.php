<?php

namespace SilverStripe\UserDocs\Admin;

use finfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleLoader;
use Symfony\Component\Filesystem\Path;

/**
 * Finds images in user documentation, so the CMS can show them without exposing them in the public folder.
 */
class UserDocsImageLocator
{
    use Injectable;

    /**
     * File extensions of images which can be shown, with their MIME types
     */
    private const MIME_TYPES = [
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];

    /**
     * Get the absolute path to a file in a documentation root.
     *
     * @param string $module The name of the module which has the documentation root
     * @param string $rootPathInModule The path to the documentation root, relative to the module root
     * @param string $pathInModule The path to the file, relative to the module root
     * @return string|null The path, or null if the file isn't a readable file in the documentation root
     */
    public function getFilePath(string $module, string $rootPathInModule, string $pathInModule): ?string
    {
        $moduleObj = ModuleLoader::getModule($module);
        // getModule() also finds modules by short name, which can be a different module
        if (!$moduleObj || $moduleObj->getName() !== $module) {
            return null;
        }
        // Path::join() resolves "..", and always gives a path in the module even if $pathInModule is absolute
        $rootPath = Path::join($moduleObj->getPath(), $rootPathInModule);
        $filePath = Path::join($moduleObj->getPath(), $pathInModule);
        if (!Path::isBasePath($rootPath, $filePath) || !is_file($filePath) || !is_readable($filePath)) {
            return null;
        }
        // Symlinks must not lead out of the documentation root
        $realRootPath = realpath($rootPath);
        $realFilePath = realpath($filePath);
        if (!$realRootPath || !$realFilePath || !Path::isBasePath($realRootPath, $realFilePath)) {
            return null;
        }
        return $realFilePath;
    }

    /**
     * Get the MIME type of an image file.
     *
     * @return string|null The MIME type, or null if the file isn't a type of image which can be shown
     */
    public function getMimeType(string $filePath): ?string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = UserDocsImageLocator::MIME_TYPES[$extension] ?? null;
        // SVG is text, so its content doesn't reliably show its type
        if ($mimeType === null || $mimeType === 'image/svg+xml') {
            return $mimeType;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if ($finfo->file($filePath) !== $mimeType) {
            return null;
        }
        return $mimeType;
    }

    /**
     * Get a hash of the content of a file, which changes when the file changes.
     */
    public function getHash(string $filePath): string
    {
        return hash_file('xxh3', $filePath);
    }
}
