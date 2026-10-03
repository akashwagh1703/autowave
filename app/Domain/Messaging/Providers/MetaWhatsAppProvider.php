<?php

namespace App\Domain\Messaging\Providers;

use App\Domain\Files\Models\Attachment;
use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Exceptions\MetaApiException;
use App\Domain\Messaging\Exceptions\PermanentDeliveryFailure;
use App\Domain\Messaging\Meta\MetaGraphClient;
use App\Domain\Messaging\Models\MessagingChannel;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Support\ChannelResolver;
use App\Domain\Messaging\Support\ContactHandle;
use Illuminate\Support\Facades\Storage;

/**
 * WhatsApp Cloud API, with the tenant's own number and token (Settings → Messaging). Text, template, or
 * one file from the inbox: uploaded to Meta first, then sent by media id with the text as its caption.
 */
class MetaWhatsAppProvider implements MessagingProvider
{
    /** Content types WhatsApp shows as a photo, video or voice note; anything else goes as a document. */
    private const MEDIA_TYPES = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'video/mp4' => 'video',
        'audio/ogg' => 'audio',
        'audio/mpeg' => 'audio',
        'audio/mp4' => 'audio',
        'audio/aac' => 'audio',
        'audio/amr' => 'audio',
    ];

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

        try {
            $payload = [
                'recipient_type' => 'individual',
                'to' => ContactHandle::whatsappDigits($handle),
                'biz_opaque_callback_data' => (string) $message->id,
                ...$this->content($message, $channel),
            ];

            return $this->client->sendWhatsApp((string) $channel->external_id, (string) $channel->credential('access_token'), $payload);
        } catch (MetaApiException $exception) {
            throw $exception->isPermanent() ? new PermanentDeliveryFailure($exception->getMessage(), 0, $exception) : $exception;
        }
    }

    /** @return array<string, mixed> */
    private function content(OutboundMessage $message, MessagingChannel $channel): array
    {
        $template = $message->template;

        if ($template) {
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

        $attachment = $message->entry()->first()?->attachment()->first();

        if (! $attachment) {
            return ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $message->body]];
        }

        return $this->media($message, $channel, $attachment);
    }

    /** @return array<string, mixed> */
    private function media(OutboundMessage $message, MessagingChannel $channel, Attachment $attachment): array
    {
        $caption = $message->body !== '' ? $message->body : null;
        $type = self::MEDIA_TYPES[$attachment->mime_type] ?? 'document';

        // Voice notes cannot carry a caption; send the file as a document so the text is not lost.
        if ($type === 'audio' && $caption !== null) {
            $type = 'document';
        }

        $contents = Storage::disk($attachment->disk)->get($attachment->path)
            ?? throw new PermanentDeliveryFailure('The attached file is missing from storage.');
        $mediaId = $this->client->uploadWhatsAppMedia((string) $channel->external_id, (string) $channel->credential('access_token'), $contents, $attachment->mime_type, $attachment->downloadName());

        return ['type' => $type, $type => array_filter([
            'id' => $mediaId,
            'caption' => $type === 'audio' ? null : $caption,
            'filename' => $type === 'document' ? $attachment->downloadName() : null,
        ])];
    }
}
