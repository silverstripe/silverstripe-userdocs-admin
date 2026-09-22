<?php

namespace SilverStripe\UserDocs\Tests\Admin\UserDocsAdminTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Admin\UserDocsManifest;

/**
 * Builds the manifest with tests directories included so fixtures can be used.
 */
class TestUserDocsManifest extends UserDocsManifest implements TestOnly
{
    public function init(bool $includeTests = false): void
    {
        parent::init(true);
    }
}
