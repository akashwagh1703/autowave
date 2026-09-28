<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Models\WebhookCall;
use Illuminate\Console\Command;

/** Runs daily. Webhook bodies contain customer messages; keep them only as long as needed for replay. */
class PruneWebhookCalls extends Command
{
    protected $signature = 'messaging:prune-webhooks {--days= : Keep calls newer than this many days (default: messaging.webhooks.retention_days)}';

    protected $description = 'Delete processed and failed Meta webhook calls older than the retention period';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('messaging.webhooks.retention_days')));

        $deleted = WebhookCall::withoutTenantScope()
            ->whereIn('status', [WebhookCall::PROCESSED, WebhookCall::FAILED])
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->components->info("Deleted {$deleted} webhook call(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
