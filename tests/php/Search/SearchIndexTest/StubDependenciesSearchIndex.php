<?php

namespace SilverStripe\UserDocs\Tests\Search\SearchIndexTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\UserDocs\Search\SearchIndex;

/**
 * Uses the given dependencies instead of the dependencies available on this server.
 */
class StubDependenciesSearchIndex extends SearchIndex implements TestOnly
{
    public function __construct(private array $pdoDrivers, private bool $hasPredisClient)
    {
    }

    protected function getAvailablePdoDrivers(): array
    {
        return $this->pdoDrivers;
    }

    protected function hasPredisClient(): bool
    {
        return $this->hasPredisClient;
    }
}
