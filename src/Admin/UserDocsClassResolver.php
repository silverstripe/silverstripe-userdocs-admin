<?php

namespace SilverStripe\UserDocs\Admin;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;

/**
 * Finds the docs for a class, from the classes that docs name in their frontmatter.
 * The first doc for a class in UserDocsManifest::getLocalisedClassMap() is the main doc for that class.
 */
class UserDocsClassResolver
{
    use Injectable;

    /**
     * Get the slugs of the docs that name the class itself, with the main doc first.
     *
     * @return string[]
     */
    public function getSlugsForClass(string $class): array
    {
        $classMap = UserDocsManifest::singleton()->getLocalisedClassMap();
        return $classMap[strtolower(ltrim($class, '\\'))] ?? [];
    }

    /**
     * Get the slug of the main doc for the nearest parent class that has docs. The class itself isn't used.
     */
    public function getSlugForParentClass(string $class): ?string
    {
        $ancestry = ClassInfo::ancestry($class);
        array_pop($ancestry);
        return $this->getFirstSlug(array_reverse($ancestry));
    }

    /**
     * Get the first slug for the first class that has docs.
     *
     * @param string[] $classes
     */
    private function getFirstSlug(array $classes): ?string
    {
        foreach ($classes as $class) {
            $slugs = $this->getSlugsForClass($class);
            if (!empty($slugs)) {
                return $slugs[0];
            }
        }
        return null;
    }
}
