<?php

namespace App\Domain\Messaging\Jobs;

use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Delivers one outbound message on the `messaging` queue, in the message's tenant context. */
class SendOutboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue(config('messaging.queue'));
        $this->tries = (int) config('messaging.tries');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('messaging.backoff');
    }

    public function handle(MessagingService $messaging, TenantContext $context): void
    {
        $this->inTenant($context, fn (OutboundMessage $message) => $messaging->deliver($message));
    }

    public function failed(?Throwable $exception): void
    {
        $this->inTenant(app(TenantContext::class), fn (OutboundMessage $message) => app(MessagingService::class)->markFailed($message, $exception));
    }

    /** @param  callable(OutboundMessage): void  $callback */
    private function inTenant(TenantContext $context, callable $callback): void
    {
        $message = OutboundMessage::withoutTenantScope()->find($this->messageId);
        $tenant = $message ? Tenant::query()->find($message->tenant_id) : null;

        if ($message && $tenant) {
            $context->run($tenant, fn () => $callback($message));
        }
    }
}
