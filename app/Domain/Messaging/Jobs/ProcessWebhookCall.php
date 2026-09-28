<?php

namespace App\Domain\Messaging\Jobs;

use App\Domain\Messaging\Actions\ReceiveInboundMessage;
use App\Domain\Messaging\Inbound\InboundMessage;
use App\Domain\Messaging\Meta\MetaWebhookNormalizer;
use App\Domain\Messaging\Models\WebhookCall;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Processes one stored webhook call in its tenant: normalise, then hand each event to the core.
 * Safe to run twice — inbound messages are idempotent and receipts only move forward.
 */
class ProcessWebhookCall implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public readonly int $callId)
    {
        $this->onQueue(config('messaging.queue'));
        $this->tries = (int) config('messaging.tries');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('messaging.backoff');
    }

    public function handle(TenantContext $context, MetaWebhookNormalizer $normalizer, ReceiveInboundMessage $receive, MessagingService $messaging): void
    {
        $call = WebhookCall::withoutTenantScope()->find($this->callId);
        $tenant = $call ? Tenant::query()->find($call->tenant_id) : null;

        if (! $call || ! $tenant || $call->status === WebhookCall::PROCESSED) {
            return;
        }

        $context->run($tenant, function () use ($call, $normalizer, $receive, $messaging) {
            $call->forceFill(['attempts' => $call->attempts + 1])->save();
            $channel = $call->channel()->first();

            foreach ($channel ? $normalizer->normalize($channel, $call->payload ?? []) : [] as $event) {
                $event instanceof InboundMessage ? $receive->handle($event) : $messaging->applyReceipt($event);
            }

            $call->forceFill(['status' => WebhookCall::PROCESSED, 'processed_at' => now(), 'error' => null])->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        WebhookCall::withoutTenantScope()->whereKey($this->callId)->update([
            'status' => WebhookCall::FAILED,
            'error' => Str::limit($exception?->getMessage() ?? 'Unknown error', 1000, ''),
            'updated_at' => now(),
        ]);
    }
}
