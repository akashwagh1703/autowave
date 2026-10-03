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
     * @param  ?array{id?: string, url?: string, filename?: ?string}  $media  where to fetch the attached file: a
     *                                                                        WhatsApp media id or an Instagram CDN URL
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
        public ?array $media = null,
    ) {}
}
