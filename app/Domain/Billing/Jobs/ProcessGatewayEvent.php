<?php

namespace App\Domain\Billing\Jobs;

use App\Domain\Billing\Actions\OnlineCheckout;
use App\Domain\Billing\Gateways\GatewayEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A verified gateway webhook, handled off the request so the gateway gets its 200 at once. Retried with
 * backoff when the gateway API can't be reached; completing a payment twice changes nothing.
 */
class ProcessGatewayEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly GatewayEvent $event)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(OnlineCheckout $checkout): void
    {
        $checkout->handle($this->event);
    }
}
