<?php

namespace App\Domain\Messaging\Meta;

use App\Domain\Messaging\Exceptions\MediaTooLarge;
use App\Domain\Messaging\Exceptions\MetaApiException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

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

    /**
     * Uploads a file for a WhatsApp message; Meta keeps it for 30 days.
     *
     * @return string the media id to send
     */
    public function uploadWhatsAppMedia(string $phoneNumberId, string $token, string $contents, string $mimeType, string $filename): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->timeout((int) config('messaging.meta.media_timeout'))
            ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
            ->post($this->graph("{$phoneNumberId}/media"), ['messaging_product' => 'whatsapp', 'type' => $mimeType]), $token);

        return (string) ($response->json('id') ?? throw new MetaApiException('Meta did not return a media id.', $response->status()));
    }

    /**
     * Where to download a file a contact sent on WhatsApp (the URL is valid for a few minutes).
     *
     * @return array{url: string, mime_type: ?string, file_size: ?int}
     */
    public function whatsAppMedia(string $mediaId, string $token): array
    {
        $response = $this->get($this->graph($mediaId), $token);
        $url = $response->json('url');

        if (! is_string($url) || $url === '') {
            throw new MetaApiException('Meta did not return a media URL.', $response->status());
        }

        return [
            'url' => $url,
            'mime_type' => is_string($response->json('mime_type')) ? $response->json('mime_type') : null,
            'file_size' => is_numeric($response->json('file_size')) ? (int) $response->json('file_size') : null,
        ];
    }

    /**
     * Downloads a file from Meta's media servers: HTTPS on config('messaging.meta.media_hosts') only, never
     * more than $maxBytes. WhatsApp needs the token; Instagram CDN links are signed and need none.
     *
     * @throws MediaTooLarge
     */
    public function downloadMedia(string $url, ?string $token, int $maxBytes): string
    {
        if (! self::isMediaUrl($url)) {
            throw new MetaApiException('The file is not on a Meta media server.', 400);
        }

        $http = Http::timeout((int) config('messaging.meta.media_timeout'))->withOptions([
            'allow_redirects' => [
                'max' => 3,
                'protocols' => ['https'],
                'on_redirect' => function ($request, $response, UriInterface $uri) {
                    if (! self::isMediaUrl((string) $uri)) {
                        throw new MetaApiException('The file is not on a Meta media server.', 400);
                    }
                },
            ],
            'on_headers' => function (ResponseInterface $response) use ($maxBytes) {
                if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                    throw new MediaTooLarge;
                }
            },
        ]);

        try {
            $response = ($token ? $http->withToken($token) : $http)->get($url);
        } catch (ConnectionException $exception) {
            throw new MetaApiException('Could not reach Meta: '.Str::limit($exception->getMessage(), 200));
        } catch (TransferException $exception) {
            $cause = $exception->getPrevious();
            throw $cause instanceof MediaTooLarge || $cause instanceof MetaApiException ? $cause : new MetaApiException('Could not download the file: '.Str::limit($exception->getMessage(), 200));
        }

        if ($response->failed()) {
            throw new MetaApiException('Meta returned HTTP '.$response->status().' for the file.', $response->status());
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new MediaTooLarge;
        }

        return $body;
    }

    public static function isMediaUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? null) !== 'https' || $host === '') {
            return false;
        }

        foreach ((array) config('messaging.meta.media_hosts') as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
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
