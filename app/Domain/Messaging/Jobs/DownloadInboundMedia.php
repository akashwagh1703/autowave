<?php

namespace App\Domain\Messaging\Jobs;

use App\Domain\Files\Actions\ManageAttachments;
use App\Domain\Messaging\Exceptions\MediaTooLarge;
use App\Domain\Messaging\Exceptions\MetaApiException;
use App\Domain\Messaging\Meta\MetaGraphClient;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Support\ChannelResolver;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Fetches the file a contact sent (photo, video, voice note, document or sticker) into private storage and
 * attaches it to the message, on the media queue. The type and size are checked like any upload
 * (config('files.owners.conversation_message')) and count against the storage allowance. A file that is
 * refused keeps its "[Image]"-style text, with the reason in meta.media.error.
 */
class DownloadInboundMedia implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue(config('messaging.media_queue'));
        $this->tries = (int) config('messaging.tries');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('messaging.backoff');
    }

    public function handle(TenantContext $context, MetaGraphClient $client, ManageAttachments $attachments, ChannelResolver $channels): void
    {
        $message = $this->pending();
        $tenant = $message ? Tenant::query()->find($message->tenant_id) : null;

        if (! $message || ! $tenant) {
            return;
        }

        $context->run($tenant, function () use ($message, $client, $attachments, $channels) {
            try {
                $file = $this->fetch($message, $client, $channels);
            } catch (MediaTooLarge) {
                $this->skip($message, __('The file is larger than :max MB, so it was not saved.', ['max' => intdiv(self::maxBytes(), 1024 * 1024)]));

                return;
            } catch (MetaApiException $exception) {
                if (! $exception->isPermanent()) {
                    throw $exception;
                }

                $this->skip($message, __('The file could not be downloaded: :error', ['error' => Str::limit($exception->getMessage(), 200)]));

                return;
            }

            try {
                $attachments->upload($message, 'conversation_message', $file);
            } catch (ValidationException $exception) {
                $this->skip($message, (string) collect($exception->errors())->flatten()->first());

                return;
            } finally {
                @unlink($file->getRealPath());
            }

            $this->mark($message, ['status' => ConversationMessage::MEDIA_STORED]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        if ($message = $this->pending()) {
            $this->skip($message, __('The file could not be downloaded. Ask the contact to send it again.'));
        }
    }

    private function pending(): ?ConversationMessage
    {
        $message = ConversationMessage::withoutTenantScope()->find($this->messageId);

        return ($message?->meta['media']['status'] ?? null) === ConversationMessage::MEDIA_PENDING ? $message : null;
    }

    private function fetch(ConversationMessage $message, MetaGraphClient $client, ChannelResolver $channels): UploadedFile
    {
        $media = $message->meta['media'];
        $max = self::maxBytes();

        if (isset($media['id'])) {
            $channel = $channels->connected('whatsapp') ?? throw new MetaApiException('WhatsApp is not connected.', 400);
            $token = (string) $channel->credential('access_token');
            $info = $client->whatsAppMedia((string) $media['id'], $token);

            if ($info['file_size'] !== null && $info['file_size'] > $max) {
                throw new MediaTooLarge;
            }

            $contents = $client->downloadMedia($info['url'], $token, $max);
        } else {
            $contents = $client->downloadMedia((string) ($media['url'] ?? ''), null, $max);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'aw-media-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $this->fileName($message), null, null, true);
    }

    private function fileName(ConversationMessage $message): string
    {
        $name = $message->meta['media']['filename'] ?? null;

        return is_string($name) && $name !== '' ? $name : Str::headline($message->type).' '.$message->sent_at->format('Y-m-d H.i');
    }

    private function skip(ConversationMessage $message, string $reason): void
    {
        $this->mark($message, ['status' => ConversationMessage::MEDIA_SKIPPED, 'error' => $reason]);
    }

    /** @param  array<string, string>  $media */
    private function mark(ConversationMessage $message, array $media): void
    {
        $message->forceFill(['meta' => [...($message->meta ?? []), 'media' => $media]])->save();
    }

    private static function maxBytes(): int
    {
        return max(array_column(ManageAttachments::kindsFor(config('files.owners.conversation_message')), 'max_kb')) * 1024;
    }
}
