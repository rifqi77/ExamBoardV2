<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // SAFETY GUARD — runs before the app boots / RefreshDatabase migrates.
        // The suite is destructive (it wipes + re-migrates the DB), so refuse
        // to run against anything that isn't an explicit *_test database.
        $db = (string) (env('DB_DATABASE') ?? '');
        if ($db !== '' && $db !== ':memory:' && ! str_ends_with($db, '_test')) {
            throw new \RuntimeException(
                "Refusing to run tests against non-test database '{$db}'. ".
                'Point DB_DATABASE at a *_test database in phpunit.xml.'
            );
        }

        parent::setUp();
    }
}
