<?php

namespace SilverStripe\UserDocs\Tests;

use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Manifest\ModuleManifest;

/**
 * Registers the modules in fixtures/extensions so tests can have docs from several modules.
 * Call popExtensionModules() in tearDown().
 */
trait ExtensionModulesTrait
{
    private bool $hasPushedExtensionModules = false;

    /**
     * Add the fixture modules to the module manifest, in priority order (highest first).
     * They have a higher priority than all other modules, so that files in them belong to them
     * and not to the userdocs-admin module that contains them.
     *
     * @param string[] $moduleNames Names of folders in fixtures/extensions without the "module_" prefix
     */
    private function pushExtensionModules(array $moduleNames): void
    {
        $manifest = clone ModuleLoader::inst()->getManifest();
        $priority = [];
        foreach ($moduleNames as $moduleName) {
            $manifest->addModule($this->getExtensionModulePath($moduleName));
            $priority[] = "test/module-$moduleName";
        }
        ModuleManifest::config()->set('module_priority', [...$priority, '$project', '$other_modules']);
        $manifest->sort();
        ModuleLoader::inst()->pushManifest($manifest);
        $this->hasPushedExtensionModules = true;
    }

    private function popExtensionModules(): void
    {
        if ($this->hasPushedExtensionModules) {
            ModuleLoader::inst()->popManifest();
            $this->hasPushedExtensionModules = false;
        }
    }

    private function getExtensionModulePath(string $moduleName): string
    {
        return __DIR__ . "/fixtures/extensions/module_$moduleName";
    }
}
