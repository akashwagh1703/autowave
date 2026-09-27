<?php

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    public function test_test_suite_runs_on_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_health_command_passes(): void
    {
        $this->artisan('autowave:health')->assertSuccessful();
    }
}
