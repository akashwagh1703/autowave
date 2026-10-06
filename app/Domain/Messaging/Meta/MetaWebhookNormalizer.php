<?php

namespace App\Domain\Messaging\Meta;

use App\Domain\Messaging\Inbound\InboundMessage;
use App\Domain\Messaging\Inbound\StatusUpdate;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Models\MessagingChannel;
use App\Domain\Messaging\Support\ContactHandle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Turns a Meta webhook body into provider-neutral events (master prompt §44). Only this class knows the
 * WhatsApp Cloud API and Instagram webhook shapes. Entries for another number or account are ignored.
 */
class MetaWebhookNormalizer
{
    private const PLACEHOLDERS = ConversationMessage::PLACEHOLDERS;

    /** Message types whose file is downloaded into the inbox (DownloadInboundMedia). */
    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    /** Instagram attachment types, in AutoWave's terms. */
    private const INSTAGRAM_TYPES = ['image' => 'image', 'video' => 'video', 'audio' => 'audio', 'file' => 'document'];

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboundMessage|StatusUpdate>
     */
    public function normalize(MessagingChannel $channel, array $payload): array
    {
        return match (true) {
            ($payload['object'] ?? null) === 'whatsapp_business_account' && $channel->channel === 'whatsapp' => $this->whatsapp($channel, $payload),
            ($payload['object'] ?? null) === 'instagram' && $channel->channel === 'instagram' => $this->instagram($channel, $payload),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboundMessage|StatusUpdate>
     */
    private function whatsapp(MessagingChannel $channel, array $payload): array
    {
        $events = [];

        foreach ($this->list($payload['entry'] ?? null) as $entry) {
            foreach ($this->list($entry['changes'] ?? null) as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                if (($change['field'] ?? null) !== 'messages' || (string) Arr::get($value, 'metadata.phone_number_id') !== (string) $channel->external_id) {
                    continue;
                }

                $names = [];
                foreach ($this->list($value['contacts'] ?? null) as $contact) {
                    $names[(string) ($contact['wa_id'] ?? '')] = $this->string(Arr::get($contact, 'profile.name'), 150);
                }

                foreach ($this->list($value['messages'] ?? null) as $message) {
                    $handle = ContactHandle::fromWaId((string) ($message['from'] ?? ''));
                    $id = $this->string($message['id'] ?? null, 191);
                    $type = (string) ($message['type'] ?? 'unsupported');

                    if (! $handle || ! $id || $type === 'reaction') {
                        continue;
                    }

                    [$type, $text] = $this->whatsappContent($type, $message);
                    $mediaId = in_array($type, self::MEDIA_TYPES, true) ? $this->string(Arr::get($message, "{$type}.id"), 191) : null;

                    $events[] = new InboundMessage(
                        channel: 'whatsapp',
                        handle: $handle,
                        providerMessageId: $id,
                        type: $type,
                        text: $text,
                        occurredAt: $this->time($message['timestamp'] ?? null),
                        name: $names[(string) $message['from']] ?? null,
                        meta: array_filter([
                            'reply_to' => $this->string(Arr::get($message, 'context.id'), 191),
                            // Which button or list row was tapped (the WhatsApp assistant's option ids).
                            'reply_id' => $this->string(Arr::get($message, 'interactive.button_reply.id') ?? Arr::get($message, 'interactive.list_reply.id') ?? Arr::get($message, 'button.payload'), 200),
                        ]),
                        media: $mediaId ? ['id' => $mediaId, 'filename' => $this->string(Arr::get($message, "{$type}.filename"), 200)] : null,
                    );
                }

                foreach ($this->list($value['statuses'] ?? null) as $status) {
                    $id = $this->string($status['id'] ?? null, 191);
                    $state = (string) ($status['status'] ?? '');

                    if (! $id || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                        continue;
                    }

                    $error = $this->list($status['errors'] ?? null)[0] ?? null;
                    $callback = $status['biz_opaque_callback_data'] ?? null;

                    $events[] = new StatusUpdate(
                        channel: 'whatsapp',
                        providerMessageId: $id,
                        status: $state,
                        occurredAt: $this->time($status['timestamp'] ?? null),
                        error: $error ? $this->string(trim(($error['title'] ?? '').' '.(Arr::get($error, 'error_data.details') ?? $error['message'] ?? '')), 500) : null,
                        outboundMessageId: is_string($callback) && ctype_digit($callback) ? (int) $callback : null,
                    );
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0: string, 1: ?string}
     */
    private function whatsappContent(string $type, array $message): array
    {
        return match ($type) {
            'text' => ['text', $this->string(Arr::get($message, 'text.body'), 4096)],
            'button' => ['text', $this->string(Arr::get($message, 'button.text'), 4096)],
            'interactive' => ['interactive', $this->string(Arr::get($message, 'interactive.button_reply.title') ?? Arr::get($message, 'interactive.list_reply.title'), 4096)],
            'image', 'video', 'document' => [$type, trim(self::PLACEHOLDERS[$type].' '.($this->string(Arr::get($message, "{$type}.caption"), 1000) ?? ''))],
            'audio', 'sticker', 'location', 'contacts' => [$type, self::PLACEHOLDERS[$type]],
            default => ['unsupported', self::PLACEHOLDERS['unsupported']],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboundMessage|StatusUpdate>
     */
    private function instagram(MessagingChannel $channel, array $payload): array
    {
        $events = [];

        foreach ($this->list($payload['entry'] ?? null) as $entry) {
            if ((string) ($entry['id'] ?? '') !== (string) $channel->external_id) {
                continue;
            }

            foreach ($this->list($entry['messaging'] ?? null) as $event) {
                $sender = ContactHandle::for('instagram', (string) Arr::get($event, 'sender.id'));
                $message = is_array($event['message'] ?? null) ? $event['message'] : null;

                if ($message && $sender && $sender !== (string) $channel->external_id && empty($message['is_echo']) && empty($message['is_deleted'])) {
                    $id = $this->string($message['mid'] ?? null, 191);

                    if (! $id) {
                        continue;
                    }

                    $text = $this->string($message['text'] ?? null, 4096);
                    $type = 'text';
                    $url = null;

                    if ($text === null && $this->list($message['attachments'] ?? null) !== []) {
                        $attachment = $this->list($message['attachments'])[0];
                        $type = self::INSTAGRAM_TYPES[(string) ($attachment['type'] ?? '')] ?? 'unsupported';
                        $text = self::PLACEHOLDERS[$type];
                        $url = $type !== 'unsupported' ? $this->string(Arr::get($attachment, 'payload.url'), 2000) : null;
                    }

                    $events[] = new InboundMessage(
                        channel: 'instagram',
                        handle: $sender,
                        providerMessageId: $id,
                        type: $type,
                        text: $text ?? self::PLACEHOLDERS['unsupported'],
                        occurredAt: $this->time(isset($event['timestamp']) ? intdiv((int) $event['timestamp'], 1000) : null),
                        media: $url ? ['url' => $url] : null,
                    );
                }

                if ($mid = $this->string(Arr::get($event, 'read.mid'), 191)) {
                    $events[] = new StatusUpdate('instagram', $mid, 'read', $this->time(isset($event['timestamp']) ? intdiv((int) $event['timestamp'], 1000) : null));
                }
            }
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function time(mixed $timestamp): CarbonImmutable
    {
        $seconds = is_numeric($timestamp) ? (int) $timestamp : 0;

        // Meta's clock and ours may differ slightly; never accept a time far in the future.
        return $seconds > 0 && $seconds <= now()->addMinutes(5)->getTimestamp()
            ? CarbonImmutable::createFromTimestampUTC($seconds)
            : CarbonImmutable::now('UTC');
    }
}
