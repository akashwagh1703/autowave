<?php

namespace App\Domain\Messaging\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Messaging\Exceptions\MetaApiException;
use App\Domain\Messaging\Meta\MetaGraphClient;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Copies the WhatsApp Business Account's templates from Meta. Templates Meta no longer returns are
 * removed. Only the BODY text is kept; headers, footers and buttons are sent as approved by Meta.
 */
class SyncTemplates
{
    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly ChannelResolver $channels,
        private readonly AuditLogger $audit,
    ) {}

    /** @return int number of templates now stored */
    public function handle(): int
    {
        $channel = $this->channels->connected('whatsapp');

        if (! $channel || ! $channel->business_account_id) {
            throw ValidationException::withMessages(['templates' => __('Connect WhatsApp with a WhatsApp Business Account id first.')]);
        }

        try {
            $remote = $this->client->whatsAppTemplates($channel->business_account_id, (string) $channel->credential('access_token'));
        } catch (MetaApiException $exception) {
            throw ValidationException::withMessages(['templates' => __('Meta did not return the templates: :error', ['error' => $exception->getMessage()])]);
        }

        $now = now();

        $kept = DB::transaction(function () use ($remote, $now) {
            $kept = [];

            foreach ($remote as $template) {
                $name = is_string($template['name'] ?? null) ? Str::limit($template['name'], 512, '') : null;
                $language = is_string($template['language'] ?? null) ? Str::limit($template['language'], 20, '') : null;

                if (! $name || ! $language) {
                    continue;
                }

                $body = collect(is_array($template['components'] ?? null) ? $template['components'] : [])
                    ->first(fn ($component) => is_array($component) && strtoupper((string) ($component['type'] ?? '')) === 'BODY')['text'] ?? null;
                preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $body, $matches);

                $row = MessageTemplate::query()->updateOrCreate(
                    ['channel' => 'whatsapp', 'name' => $name, 'language' => $language],
                    [
                        'category' => is_string($template['category'] ?? null) ? Str::limit($template['category'], 30, '') : null,
                        'status' => Str::limit(strtoupper((string) ($template['status'] ?? 'UNKNOWN')), 30, ''),
                        'body' => is_string($body) ? $body : null,
                        'variables' => $matches[1] === [] ? 0 : max(array_map('intval', $matches[1])),
                        'provider_template_id' => isset($template['id']) ? Str::limit((string) $template['id'], 64, '') : null,
                        'synced_at' => $now,
                    ],
                );
                $kept[] = $row->id;
            }

            MessageTemplate::query()->where('channel', 'whatsapp')->whereNotIn('id', $kept ?: [0])->delete();

            return $kept;
        });

        $this->audit->log('messaging.templates_synced', null, ['count' => count($kept)]);

        return count($kept);
    }
}
