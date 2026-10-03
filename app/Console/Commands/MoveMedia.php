<?php

namespace App\Console\Commands;

use App\Domain\Media\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Copies every uploaded image to another disk (for example from the local `public` disk to MinIO)
 * and points its row at the new disk. Safe to run again: rows already on the target are skipped.
 */
class MoveMedia extends Command
{
    protected $signature = 'autowave:media-move
        {disk : Target disk, for example media}
        {--delete-source : Delete each file from its old disk once it is copied}
        {--dry-run : Only report what would be copied}';

    protected $description = 'Copy uploaded images to another storage disk and switch them over';

    public function handle(): int
    {
        $target = (string) $this->argument('disk');

        if (! is_array(config("filesystems.disks.{$target}"))) {
            $this->components->error("Unknown disk {$target}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['copied' => 0, 'missing' => 0, 'failed' => 0];

        // Platform maintenance across all businesses.
        Media::withoutTenantScope()
            ->where('disk', '!=', $target)
            ->chunkById(100, function (Collection $rows) use ($target, $dryRun, &$counts) {
                foreach ($rows as $media) {
                    $counts[$this->move($media, $target, $dryRun)]++;
                }
            });

        $this->components->twoColumnDetail($dryRun ? 'Would copy' : 'Copied', (string) $counts['copied']);
        $this->components->twoColumnDetail('Missing source file', (string) $counts['missing']);
        $this->components->twoColumnDetail('Failed', (string) $counts['failed']);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return 'copied'|'missing'|'failed' */
    private function move(Media $media, string $target, bool $dryRun): string
    {
        $source = Storage::disk($media->disk);

        if (! $source->exists($media->path)) {
            $this->components->warn("Media #{$media->id}: file not found on {$media->disk} ({$media->path}).");

            return 'missing';
        }

        if ($dryRun) {
            return 'copied';
        }

        try {
            $stream = $source->readStream($media->path);
            Storage::disk($target)->writeStream($media->path, $stream, ['ContentType' => $media->mime_type]);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $from = $media->disk;
            $media->update(['disk' => $target]);

            if ($this->option('delete-source')) {
                Storage::disk($from)->delete($media->path);
            }

            return 'copied';
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error("Media #{$media->id}: {$exception->getMessage()}");

            return 'failed';
        }
    }
}
