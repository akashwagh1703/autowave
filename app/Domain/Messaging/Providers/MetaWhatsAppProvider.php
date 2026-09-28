<?php

namespace App\Domain\Messaging\Providers;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Exceptions\MetaApiException;
use App\Domain\Messaging\Exceptions\PermanentDeliveryFailure;
use App\Domain\Messaging\Meta\MetaGraphClient;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Support\ChannelResolver;
use App\Domain\Messaging\Support\ContactHandle;

/** WhatsApp Cloud API, with the tenant's own number and token (Settings → Messaging). Text or template. */
class MetaWhatsAppProvider implements MessagingProvider
{
    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly ChannelResolver $channels,
    ) {}

    public function send(OutboundMessage $message): string
    {
        if ($message->channel !== 'whatsapp') {
            throw new PermanentDeliveryFailure("The WhatsApp provider cannot send {$message->channel} messages.");
        }

        $channel = $this->channels->connected('whatsapp')
            ?? throw new PermanentDeliveryFailure('WhatsApp is not connected for this business.');
        $handle = ContactHandle::for('whatsapp', $message->recipient)
            ?? throw new PermanentDeliveryFailure('The recipient is not a valid phone number.');

        $payload = [
            'recipient_type' => 'individual',
            'to' => ContactHandle::whatsappDigits($handle),
            'biz_opaque_callback_data' => (string) $message->id,
            ...$this->content($message),
        ];

        try {
            return $this->client->sendWhatsApp((string) $channel->external_id, (string) $channel->credential('access_token'), $payload);
        } catch (MetaApiException $exception) {
            throw $exception->isPermanent() ? new PermanentDeliveryFailure($exception->getMessage(), 0, $exception) : $exception;
        }
    }

    /** @return array<string, mixed> */
    private function content(OutboundMessage $message): array
    {
        $template = $message->template;

        if (! $template) {
            return ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $message->body]];
        }

        $params = array_values(array_map('strval', $template['params'] ?? []));

        return ['type' => 'template', 'template' => array_filter([
            'name' => $template['name'],
            'language' => ['code' => $template['language']],
            'components' => $params === [] ? null : [[
                'type' => 'body',
                'parameters' => array_map(fn (string $text) => ['type' => 'text', 'text' => $text], $params),
            ]],
        ])];
    }
}
