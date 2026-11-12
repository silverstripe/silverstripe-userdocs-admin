<?php

namespace SilverStripe\UserDocs\Tests\Task\IndexDocumentationTaskTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Admin\UserDocsManifest;

/**
 * Records whether the manifest is initialised, and has docs in the "en" and "fr" locales.
 */
class RecordingUserDocsManifest extends UserDocsManifest implements TestOnly
{
    public bool $initialised = false;

    public function init(bool $includeTests = false): void
    {
        $this->initialised = true;
    }

    public function getDocData(?string $locale = null): array
    {
        return [
            'en' => ['page' => []],
            'fr' => ['page' => []],
        ];
    }
}
