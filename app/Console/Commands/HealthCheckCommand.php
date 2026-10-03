<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class HealthCheckCommand extends Command
{
    protected $signature = 'autowave:health';

    protected $description = 'Verify connectivity to PostgreSQL, Redis, the cache store and media storage';

    public function handle(): int
    {
        $checks = [
            'Database' => fn () => $this->checkDatabase(),
            'Redis' => fn () => $this->checkRedis(),
            'Cache' => fn () => $this->checkCache(),
            'Queue' => fn () => $this->checkQueue(),
            'Media storage' => fn () => $this->checkMediaStorage(),
            'Private files' => fn () => $this->checkPrivateFiles(),
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

    /** Uploads fail, or images break, if object storage is unreachable or has no public URL. */
    private function checkMediaStorage(): string
    {
        $disk = (string) config('website.media.disk');

        if (config("filesystems.disks.{$disk}.driver") !== 's3') {
            return "{$disk} (local)";
        }

        if (blank(config("filesystems.disks.{$disk}.url"))) {
            throw new \RuntimeException("disk {$disk} has no public URL; set MEDIA_URL");
        }

        $this->roundTrip($disk);

        return sprintf('%s (bucket %s)', $disk, config("filesystems.disks.{$disk}.bucket"));
    }

    private function checkPrivateFiles(): string
    {
        $disk = (string) config('files.disks.private');

        if (config("filesystems.disks.{$disk}.driver") !== 's3') {
            return "{$disk} (local)";
        }

        $publicBuckets = collect([config('website.media.disk'), config('files.disks.public'), 'media'])
            ->map(fn (string $public) => config("filesystems.disks.{$public}.bucket"))
            ->filter();

        if ($publicBuckets->contains(config("filesystems.disks.{$disk}.bucket"))) {
            throw new \RuntimeException("disk {$disk} uses the public media bucket; set MEDIA_PRIVATE_BUCKET to a private bucket");
        }

        $this->roundTrip($disk);

        return sprintf('%s (bucket %s)', $disk, config("filesystems.disks.{$disk}.bucket"));
    }

    private function roundTrip(string $disk): void
    {
        $storage = Storage::disk($disk);
        $path = 'health/'.Str::random(16).'.txt';
        $storage->put($path, 'ok');
        $value = $storage->get($path);
        $storage->delete($path);

        if ($value !== 'ok') {
            throw new \RuntimeException("object storage round-trip on {$disk} returned an unexpected value");
        }
    }
}
