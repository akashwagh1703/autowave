<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Services\MessagingService;
use Illuminate\Console\Command;

/**
 * Runs every minute (routes/console.php). Re-queues outbound messages whose queue message was lost
 * (queued too long) or whose worker died mid-send (sending too long). Delivery claims the message
 * first, so a message that is still on the queue is not sent twice.
 */
class DispatchPendingMessages extends Command
{
    protected $signature = 'messaging:dispatch-pending';

    protected $description = 'Re-queue outbound messages left behind by a queue outage or a crashed worker';

    public function handle(MessagingService $messaging): int
    {
        $requeued = $this->recover($messaging, MessageStatus::Queued, (int) config('messaging.stuck_queued_minutes'));
        $recovered = $this->recover($messaging, MessageStatus::Sending, (int) config('messaging.stuck_sending_minutes'));

        $this->components->info("Re-queued {$requeued} stuck queued and {$recovered} stuck sending message(s).");

        return self::SUCCESS;
    }

    private function recover(MessagingService $messaging, MessageStatus $status, int $minutes): int
    {
        $ids = OutboundMessage::withoutTenantScope()
            ->where('status', $status)
            ->where('updated_at', '<', now()->subMinutes($minutes))
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
}
