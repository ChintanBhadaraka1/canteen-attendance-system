<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function createApplication()
    {
        $app = parent::createApplication();

        // RefreshDatabase wipes the database, so never run against a real one.
        if ($app['config']['database.default'] !== 'sqlite' || $app['config']['database.connections.sqlite.database'] !== ':memory:') {
            throw new RuntimeException('Tests must use the in-memory SQLite database. Run: php artisan config:clear');
        }

        return $app;
    }
}
