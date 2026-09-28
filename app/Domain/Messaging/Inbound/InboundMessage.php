<?php

namespace App\Domain\Messaging\Inbound;

use Carbon\CarbonImmutable;

/** A message from a contact, in AutoWave's terms. Nothing provider-specific beyond the provider's message id. */
final readonly class InboundMessage
{
    /**
     * @param  string  $handle  normalised phone (WhatsApp) or scoped user id (Instagram)
     * @param  string  $type  text, image, audio, video, document, sticker, location, contacts, interactive, unsupported
     * @param  array<string, scalar|null>  $meta
     */
    public function __construct(
        public string $channel,
        public string $handle,
        public string $providerMessageId,
        public string $type,
        public ?string $text,
        public CarbonImmutable $occurredAt,
        public ?string $name = null,
        public array $meta = [],
    ) {}
}
