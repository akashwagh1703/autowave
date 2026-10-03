<?php

namespace App\Domain\Billing\Gateways;

/** A payment as the provider reports it. Amount in paise. */
final readonly class GatewayPayment
{
    public const CAPTURED = 'captured';

    public const AUTHORIZED = 'authorized';

    public const FAILED = 'failed';

    public function __construct(
        public string $id,
        public ?string $orderId,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $method = null,
        public ?string $error = null,
    ) {}

    public function captured(): bool
    {
        return $this->status === self::CAPTURED;
    }
}
