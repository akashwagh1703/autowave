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

    public function test_health_command_fails_when_ai_jobs_could_outlive_retry_after(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.retry_after' => 90, 'ai.job_timeout' => 150]);

        $this->artisan('autowave:health')->assertFailed();

        config(['queue.connections.redis.retry_after' => 200]);

        $this->artisan('autowave:health')->assertSuccessful();
    }
}
