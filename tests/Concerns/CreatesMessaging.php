<?php

namespace Tests\Concerns;

use App\Domain\Messaging\Actions\ConnectChannel;
use App\Domain\Messaging\Actions\ReceiveInboundMessage;
use App\Domain\Messaging\Enums\ChannelStatus;
use App\Domain\Messaging\Inbound\InboundMessage;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Models\MessagingChannel;
use App\Domain\Messaging\Support\ContactHandle;
use App\Domain\Tenant\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Messaging fixtures (ADR-018). Use with CreatesCrmRecords and CreatesTenants. */
trait CreatesMessaging
{
    protected const APP_SECRET = 'test-app-secret';

    /** A connected WhatsApp channel, saved directly (no Graph call). */
    protected function connectWhatsApp(Tenant $tenant, string $phoneNumberId = '1111111111', string $businessAccountId = '2222222222'): MessagingChannel
    {
        return $this->inTenant($tenant, function () use ($phoneNumberId, $businessAccountId) {
            $channel = ConnectChannel::ensure('whatsapp');
            $channel->forceFill([
                'status' => ChannelStatus::Connected,
                'external_id' => $phoneNumberId,
                'business_account_id' => $businessAccountId,
                'display_name' => 'Test number',
                'display_handle' => '+91 90000 00000',
                'credentials' => [...$channel->credentials, 'access_token' => 'wa-token', 'app_secret' => self::APP_SECRET],
                'connected_at' => now(),
            ])->save();

            return $channel;
        });
    }

    protected function connectInstagram(Tenant $tenant, string $accountId = '17840000000000001'): MessagingChannel
    {
        return $this->inTenant($tenant, function () use ($accountId) {
            $channel = ConnectChannel::ensure('instagram');
            $channel->forceFill([
                'status' => ChannelStatus::Connected,
                'external_id' => $accountId,
                'display_name' => 'Test account',
                'display_handle' => '@test',
                'credentials' => [...$channel->credentials, 'access_token' => 'ig-token', 'app_secret' => self::APP_SECRET],
                'connected_at' => now(),
            ])->save();

            return $channel;
        });
    }

    protected function makeTemplate(Tenant $tenant, array $attributes = []): MessageTemplate
    {
        return $this->inTenant($tenant, fn () => MessageTemplate::query()->create([
            'channel' => 'whatsapp',
            'name' => 'appointment_reminder',
            'language' => 'en',
            'category' => 'UTILITY',
            'status' => MessageTemplate::APPROVED,
            'body' => 'Hi {{1}}, see you on {{2}}.',
            'variables' => 2,
            'synced_at' => now(),
            ...$attributes,
        ]));
    }

    /**
     * Feeds a message into the inbound pipeline, as a webhook would after normalisation. Options: channel,
     * id, at, name, type, and reply (the id of a tapped button or list row).
     */
    protected function receive(Tenant $tenant, string $from, string $text = 'Hello', array $options = []): Conversation
    {
        $channel = $options['channel'] ?? 'whatsapp';

        return $this->inTenant($tenant, function () use ($channel, $from, $text, $options) {
            $handle = ContactHandle::for($channel, $from);
            app(ReceiveInboundMessage::class)->handle(new InboundMessage(
                channel: $channel,
                handle: $handle,
                providerMessageId: $options['id'] ?? 'wamid.'.Str::random(20),
                type: isset($options['reply']) ? 'interactive' : ($options['type'] ?? 'text'),
                text: $text,
                occurredAt: $options['at'] ?? CarbonImmutable::now('UTC'),
                name: $options['name'] ?? null,
                meta: isset($options['reply']) ? ['reply_id' => $options['reply']] : [],
            ));

            return Conversation::query()->where('channel', $channel)->where('contact_handle', $handle)->sole();
        });
    }

    /** @param  array<string, mixed>  $value  the `value` of one WhatsApp `messages` change */
    protected function whatsappPayload(array $value, string $phoneNumberId = '1111111111'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '2222222222',
                'changes' => [[
                    'field' => 'messages',
                    'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '919000000000', 'phone_number_id' => $phoneNumberId], ...$value],
                ]],
            ]],
        ];
    }

    protected function whatsappText(string $from, string $text, string $id, ?string $name = 'Priya'): array
    {
        return $this->whatsappPayload([
            'contacts' => [['profile' => ['name' => $name], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => $text]]],
        ]);
    }

    protected function whatsappStatus(string $wamid, string $status, ?int $messageId = null, array $extra = []): array
    {
        return $this->whatsappPayload([
            'statuses' => [array_filter([
                'id' => $wamid,
                'status' => $status,
                'timestamp' => (string) now()->getTimestamp(),
                'recipient_id' => '919876543210',
                'biz_opaque_callback_data' => $messageId !== null ? (string) $messageId : null,
                ...$extra,
            ])],
        ]);
    }

    protected function postWebhook(MessagingChannel $channel, array $payload, ?string $secret = self::APP_SECRET): TestResponse
    {
        $body = json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', $this->appUrl("/webhooks/meta/{$channel->webhook_key}"), [], [], [], $headers, $body);
    }
}
