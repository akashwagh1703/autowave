<?php

namespace App\Domain\Messaging\Meta;

use App\Domain\Messaging\Exceptions\MetaApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The only class that talks to Meta's Graph APIs (WhatsApp Cloud API and Instagram API with Instagram
 * Login). Tokens go in the Authorization header, never in URLs or logs.
 */
class MetaGraphClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return string the WhatsApp message id (wamid…)
     */
    public function sendWhatsApp(string $phoneNumberId, string $token, array $payload): string
    {
        $response = $this->post($this->graph("{$phoneNumberId}/messages"), $token, ['messaging_product' => 'whatsapp', ...$payload]);

        return (string) ($response->json('messages.0.id') ?? throw new MetaApiException('Meta did not return a message id.', $response->status()));
    }

    /** @return array{display_phone_number: ?string, verified_name: ?string} */
    public function whatsAppPhoneNumber(string $phoneNumberId, string $token): array
    {
        $response = $this->get($this->graph($phoneNumberId), $token, ['fields' => 'display_phone_number,verified_name']);

        return [
            'display_phone_number' => $response->json('display_phone_number'),
            'verified_name' => $response->json('verified_name'),
        ];
    }

    /**
     * Every template of a WhatsApp Business Account, following pagination up to `meta.template_pages`.
     *
     * @return list<array<string, mixed>>
     */
    public function whatsAppTemplates(string $businessAccountId, string $token): array
    {
        $url = $this->graph("{$businessAccountId}/message_templates");
        $query = ['fields' => 'id,name,language,status,category,components', 'limit' => 100];
        $templates = [];

        for ($page = 0; $page < (int) config('messaging.meta.template_pages') && $url; $page++) {
            $response = $this->get($url, $token, $query);
            $templates = [...$templates, ...array_values(array_filter((array) $response->json('data', []), 'is_array'))];
            $next = $response->json('paging.next');
            // The `next` URL already carries the query; only follow Meta's own host.
            $url = is_string($next) && str_starts_with($next, rtrim(config('messaging.meta.graph_url'), '/').'/') ? $next : null;
            $query = [];
        }

        return $templates;
    }

    /** @return string the Instagram message id */
    public function sendInstagram(string $accountId, string $token, string $recipientId, string $text): string
    {
        $response = $this->post($this->instagram("{$accountId}/messages"), $token, [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $text],
        ]);

        return (string) ($response->json('message_id') ?? throw new MetaApiException('Meta did not return a message id.', $response->status()));
    }

    /** @return array{user_id: ?string, username: ?string, name: ?string} */
    public function instagramAccount(string $token): array
    {
        $response = $this->get($this->instagram('me'), $token, ['fields' => 'user_id,username,name']);

        return [
            'user_id' => $response->json('user_id') !== null ? (string) $response->json('user_id') : null,
            'username' => $response->json('username'),
            'name' => $response->json('name'),
        ];
    }

    private function graph(string $path): string
    {
        return rtrim(config('messaging.meta.graph_url'), '/').'/'.config('messaging.meta.graph_version').'/'.$path;
    }

    private function instagram(string $path): string
    {
        return rtrim(config('messaging.meta.instagram_url'), '/').'/'.config('messaging.meta.graph_version').'/'.$path;
    }

    /** @param  array<string, mixed>  $query */
    private function get(string $url, string $token, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get($url, $query), $token);
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $url, string $token, array $body): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->asJson()->post($url, $body), $token);
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call, string $token): Response
    {
        try {
            $response = $call(Http::withToken($token)->acceptJson()->timeout((int) config('messaging.meta.timeout')));
        } catch (ConnectionException $exception) {
            throw new MetaApiException('Could not reach Meta: '.Str::limit($exception->getMessage(), 200));
        }

        if ($response->failed()) {
            $error = (array) $response->json('error', []);
            $details = $error['error_data']['details'] ?? null;
            $message = trim(($error['message'] ?? 'Meta returned HTTP '.$response->status()).(is_string($details) && $details !== '' ? ' — '.$details : ''));

            throw new MetaApiException(Str::limit($message, 500), $response->status(), isset($error['code']) ? (int) $error['code'] : null);
        }

        return $response;
    }
}
