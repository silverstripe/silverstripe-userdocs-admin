<?php

namespace SilverStripe\UserDocs\Tests\Admin\UserDocsClassResolverTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Admin\UserDocsManifest;

/**
 * A manifest with a fixed class map, so no docs need to be read.
 */
class StubUserDocsManifest extends UserDocsManifest implements TestOnly
{
    private array $classMap;

    /**
     * @param array $classMap Lists of slugs keyed by lowercase class name
     */
    public function __construct(array $classMap)
    {
        $this->classMap = $classMap;
    }

    public function getLocalisedClassMap(): array
    {
        return $this->classMap;
    }
}
