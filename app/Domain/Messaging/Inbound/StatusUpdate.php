<?php

namespace App\Domain\Messaging\Inbound;

use Carbon\CarbonImmutable;

/** A delivery receipt for a message we sent: sent, delivered, read or failed. */
final readonly class StatusUpdate
{
    public function __construct(
        public string $channel,
        public string $providerMessageId,
        public string $status,
        public CarbonImmutable $occurredAt,
        public ?string $error = null,
        public ?int $outboundMessageId = null,
    ) {}
}
