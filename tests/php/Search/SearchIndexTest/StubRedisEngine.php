<?php

namespace SilverStripe\UserDocs\Tests\Search\SearchIndexTest;

use Error;
use SilverStripe\Dev\TestOnly;
use TeamTNT\TNTSearch\Engines\RedisEngine;

/**
 * A RedisEngine that doesn't need a connection.
 * Declares the $fuzzy_no_limit property that RedisEngine is missing, to avoid a deprecation warning.
 */
class StubRedisEngine extends RedisEngine implements TestOnly
{
    /**
     * An error to throw when loading the config, to simulate a failure such as a missing Predis client.
     */
    public static ?Error $loadConfigError = null;

    public bool $fuzzy_no_limit = false;

    public function loadConfig(array $config)
    {
        if (static::$loadConfigError) {
            throw static::$loadConfigError;
        }
    }
}
