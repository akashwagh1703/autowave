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
use App\Domain\Messaging\Support\Interactive;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * WhatsApp Cloud API, with the tenant's own number and token (Settings → Messaging). Text, template,
 * reply buttons, a list or photo cards (the WhatsApp assistant), or one file from the inbox: files are uploaded to
 * Meta first, then sent by media id with the text as its caption.
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

        if ($message->interactive) {
            return $this->interactive($message, $channel, $message->interactive);
        }

        $attachment = $message->entry()->first()?->attachment()->first();

        if (! $attachment) {
            return ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $message->body]];
        }

        return $this->media($message, $channel, $attachment);
    }

    /**
     * Reply buttons, a list, cards with photos, or a photo with the text as caption (Messaging\Support\Interactive).
     *
     * @param  array<string, mixed>  $interactive
     * @return array<string, mixed>
     */
    private function interactive(OutboundMessage $message, MessagingChannel $channel, array $interactive): array
    {
        $body = mb_substr($message->body, 0, Interactive::BODY_MAX);
        $kind = $interactive['kind'] ?? null;

        if ($kind === 'image') {
            return ['type' => 'image', 'image' => array_filter([
                'id' => $this->imageId($channel, $interactive['image']),
                'caption' => $body !== '' ? $body : null,
            ])];
        }

        $footer = isset($interactive['footer']) ? ['footer' => ['text' => $interactive['footer']]] : [];

        if ($kind === 'buttons') {
            $header = isset($interactive['header_image'])
                ? ['header' => ['type' => 'image', 'image' => ['id' => $this->imageId($channel, $interactive['header_image'])]]]
                : [];

            return ['type' => 'interactive', 'interactive' => [
                'type' => 'button',
                ...$header,
                'body' => ['text' => $body],
                ...$footer,
                'action' => ['buttons' => array_map(
                    fn (array $button) => ['type' => 'reply', 'reply' => ['id' => $button['id'], 'title' => $button['title']]],
                    $interactive['buttons'] ?? [],
                )],
            ]];
        }

        if ($kind === 'carousel') {
            return ['type' => 'interactive', 'interactive' => [
                'type' => 'carousel',
                'body' => ['text' => $body],
                'action' => ['cards' => array_map(fn (array $card, int $index) => [
                    'card_index' => $index,
                    'type' => 'cta_url',
                    'header' => ['type' => 'image', 'image' => ['link' => $this->publicUrl($card['image']['url'])]],
                    ...(isset($card['text']) ? ['body' => ['text' => $card['text']]] : []),
                    'action' => ['buttons' => array_map(
                        fn (array $button) => ['type' => 'quick_reply', 'quick_reply' => ['id' => $button['id'], 'title' => $button['title']]],
                        $card['buttons'],
                    )],
                ], $interactive['cards'], array_keys($interactive['cards']))],
            ]];
        }

        if ($kind === 'list') {
            return ['type' => 'interactive', 'interactive' => [
                'type' => 'list',
                ...(isset($interactive['header']) ? ['header' => ['type' => 'text', 'text' => $interactive['header']]] : []),
                'body' => ['text' => $body],
                ...$footer,
                'action' => [
                    'button' => $interactive['button'],
                    'sections' => [['rows' => array_map(fn (array $row) => array_filter([
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'description' => $row['description'] ?? null,
                    ]), $interactive['rows'] ?? [])]],
                ],
            ]];
        }

        return ['type' => 'text', 'text' => ['preview_url' => false, 'body' => Interactive::fallbackText($message->body, $interactive)]];
    }

    /**
     * Uploads an image once and reuses Meta's media id (valid for 30 days) for later messages.
     *
     * @param  array{disk: string, path: string, mime: string}  $image
     */
    private function imageId(MessagingChannel $channel, array $image): string
    {
        $key = 'whatsapp-media:'.$channel->id.':'.sha1($image['disk'].'|'.$image['path']);

        return Cache::remember($key, now()->addDays(25), function () use ($channel, $image) {
            $contents = Storage::disk($image['disk'])->get($image['path'])
                ?? throw new PermanentDeliveryFailure('The image is missing from storage.');

            return $this->client->uploadWhatsAppMedia((string) $channel->external_id, (string) $channel->credential('access_token'), $contents, $image['mime'], basename($image['path']));
        });
    }

    /** Carousel cards take their photo by link, so Meta must be able to fetch it from the internet. */
    private function publicUrl(string $url): string
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
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
