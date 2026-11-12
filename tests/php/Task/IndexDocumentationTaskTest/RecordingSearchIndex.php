<?php

namespace SilverStripe\UserDocs\Tests\Task\IndexDocumentationTaskTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\i18n\i18n;
use SilverStripe\UserDocs\Search\SearchIndex;

/**
 * Records calls that change the search index, in the order they are made, instead of building indexes.
 */
class RecordingSearchIndex extends SearchIndex implements TestOnly
{
    public array $calls = [];

    public function __construct(private bool $supported = true)
    {
    }

    public function isSupported(): bool
    {
        return $this->supported;
    }

    public function clearIndex(): void
    {
        $this->calls[] = 'clearIndex';
    }

    public function init(): void
    {
        $this->calls[] = 'init:' . i18n::get_locale();
    }
}
