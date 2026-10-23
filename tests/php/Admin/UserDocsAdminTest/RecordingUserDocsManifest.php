<?php

namespace SilverStripe\UserDocs\Tests\Admin\UserDocsAdminTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Admin\UserDocsManifest;

/**
 * Records the paths the admin initialises the manifest with, and builds the manifest
 * with tests directories included so fixtures can be used.
 */
class RecordingUserDocsManifest extends UserDocsManifest implements TestOnly
{
    private ?array $validPaths = null;

    public function init(array $validPaths, bool $includeTests = false): void
    {
        $this->validPaths = $validPaths;
        $this->regenerate($validPaths, true);
    }

    public function getValidPaths(): ?array
    {
        return $this->validPaths;
    }
}
