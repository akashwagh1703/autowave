<?php

namespace App\Domain\Billing\Gateways;

/** A webhook event the billing flow acts on: money taken for an order, or an attempt that failed. */
final readonly class GatewayEvent
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    public function __construct(
        public string $type,
        public string $orderId,
        public string $paymentId,
        public int $amount,
        public string $currency,
        public ?string $error = null,
    ) {}
}
