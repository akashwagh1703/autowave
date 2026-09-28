<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Jobs\ProcessWebhookCall;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Models\WebhookCall;
use App\Domain\Messaging\Services\MessagingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Runs every minute (routes/console.php). Re-queues outbound messages whose queue message was lost
 * (queued too long, or past the end of quiet hours) or whose worker died mid-send (sending too long),
 * and webhook calls left pending by a queue outage. Delivery claims the message first, so a message
 * that is still on the queue is not sent twice.
 */
class DispatchPendingMessages extends Command
{
    protected $signature = 'messaging:dispatch-pending';

    protected $description = 'Re-queue outbound messages and webhook calls left behind by a queue outage or a crashed worker';

    public function handle(MessagingService $messaging): int
    {
        $requeued = $this->recover($messaging, MessageStatus::Queued, (int) config('messaging.stuck_queued_minutes'));
        $recovered = $this->recover($messaging, MessageStatus::Sending, (int) config('messaging.stuck_sending_minutes'));
        $calls = $this->recoverWebhookCalls();

        $this->components->info("Re-queued {$requeued} stuck queued and {$recovered} stuck sending message(s), and {$calls} webhook call(s).");

        return self::SUCCESS;
    }

    private function recover(MessagingService $messaging, MessageStatus $status, int $minutes): int
    {
        $cutoff = now()->subMinutes($minutes);

        $ids = OutboundMessage::withoutTenantScope()
            ->where('status', $status)
            ->where('updated_at', '<', $cutoff)
            // A message waiting for the end of quiet hours is not stuck until well after that moment.
            ->where(fn (Builder $query) => $query->whereNull('scheduled_for')->orWhere('scheduled_for', '<', $cutoff))
            ->limit((int) config('messaging.dispatch_batch'))
            ->pluck('id');

        $count = 0;

        foreach ($ids as $id) {
            $reset = OutboundMessage::withoutTenantScope()->whereKey($id)
                ->where('status', $status)
                ->update(['status' => MessageStatus::Queued, 'updated_at' => now()]);

            if ($reset === 1) {
                $messaging->dispatch(OutboundMessage::withoutTenantScope()->findOrFail($id));
                $count++;
            }
        }

        return $count;
    }

    private function recoverWebhookCalls(): int
    {
        $ids = WebhookCall::withoutTenantScope()
            ->where('status', WebhookCall::PENDING)
            ->where('updated_at', '<', now()->subMinutes((int) config('messaging.webhooks.stuck_minutes')))
            ->limit((int) config('messaging.dispatch_batch'))
            ->pluck('id');

        foreach ($ids as $id) {
            WebhookCall::withoutTenantScope()->whereKey($id)->update(['updated_at' => now()]);

            try {
                Bus::dispatch(new ProcessWebhookCall($id));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $ids->count();
    }
}
