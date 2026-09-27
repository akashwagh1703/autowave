<?php

namespace Tests;

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Catalogue and RBAC templates are seeded once per run when a test uses RefreshDatabase.
     */
    protected bool $seed = true;

    protected string $seeder = PlatformSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase wipes the database; refuse to touch anything but a *_testing database.
        $database = (string) DB::connection()->getDatabaseName();
        if (! Str::endsWith($database, '_testing')) {
            throw new RuntimeException("Refusing to run tests against database [{$database}]. Use a *_testing database (see .env.testing).");
        }

        // Backend tests must not depend on a compiled frontend (public/build).
        $this->withoutVite();
    }

    protected function appUrl(string $path = '/'): string
    {
        return 'http://'.config('autowave.hosts.app').$path;
    }

    protected function adminUrl(string $path = '/'): string
    {
        return 'http://'.config('autowave.hosts.admin').$path;
    }

    protected function marketingUrl(string $path = '/'): string
    {
        return 'http://'.config('autowave.hosts.marketing').$path;
    }

    protected function siteUrl(string $host, string $path = '/'): string
    {
        return 'http://'.$host.$path;
    }
}
