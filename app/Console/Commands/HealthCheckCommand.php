<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class HealthCheckCommand extends Command
{
    protected $signature = 'autowave:health';

    protected $description = 'Verify connectivity to PostgreSQL, Redis and the cache store';

    public function handle(): int
    {
        $checks = [
            'Database' => fn () => $this->checkDatabase(),
            'Redis' => fn () => $this->checkRedis(),
            'Cache' => fn () => $this->checkCache(),
            'Queue' => fn () => $this->checkQueue(),
        ];

        $failed = false;

        foreach ($checks as $name => $check) {
            try {
                $this->components->twoColumnDetail($name, '<fg=green>'.$check().'</>');
            } catch (Throwable $e) {
                $failed = true;
                $this->components->twoColumnDetail($name, '<fg=red>FAILED</> '.$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkDatabase(): string
    {
        $connection = DB::connection();
        $connection->select('select 1');

        return sprintf('%s (%s)', $connection->getDriverName(), $connection->getDatabaseName());
    }

    private function checkRedis(): string
    {
        $pong = Redis::connection()->ping();

        return 'ping: '.(is_object($pong) ? (string) $pong : var_export($pong, true));
    }

    private function checkCache(): string
    {
        $key = 'autowave:health:'.Str::random(8);
        Cache::put($key, 'ok', 10);
        $value = Cache::pull($key);

        if ($value !== 'ok') {
            throw new \RuntimeException('cache round-trip returned an unexpected value');
        }

        return 'store: '.config('cache.default');
    }

    /**
     * A job still running when retry_after passes is handed out again, so an automation step could
     * send the same message twice.
     */
    private function checkQueue(): string
    {
        $connection = (string) config('queue.default');
        $retryAfter = config("queue.connections.{$connection}.retry_after");
        $jobTimeout = (int) config('ai.job_timeout');

        if ($retryAfter !== null && (int) $retryAfter <= $jobTimeout) {
            throw new \RuntimeException("retry_after ({$retryAfter}s) must be larger than the AI job timeout ({$jobTimeout}s); raise REDIS_QUEUE_RETRY_AFTER");
        }

        return sprintf('%s, job timeout %ds', $connection, $jobTimeout);
    }
}
