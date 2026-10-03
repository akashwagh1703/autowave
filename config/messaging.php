<?php

use App\Domain\Messaging\Providers\InstagramProvider;
use App\Domain\Messaging\Providers\LogProvider;
use App\Domain\Messaging\Providers\MailProvider;
use App\Domain\Messaging\Providers\MetaWhatsAppProvider;

/*
|--------------------------------------------------------------------------
| Messaging (Phase 5 outbound foundation, Phase 8 channels — ADR-015, ADR-018)
|--------------------------------------------------------------------------
|
| Business code calls MessagingService, never a provider (master prompt §43).
|
| A channel uses its `connected_provider` when the tenant has connected that
| channel in Settings → Messaging, and its `provider` otherwise. `log` is a
| simulated provider: messages are recorded and marked sent, but nothing
| leaves the server.
|
*/

return [

    'channels' => [
        'whatsapp' => [
            'label' => 'WhatsApp',
            'provider' => env('MESSAGING_WHATSAPP_PROVIDER', 'log'),
            'connected_provider' => 'meta_whatsapp',
            'conversations' => true,
            'templates' => true,
        ],
        'instagram' => [
            'label' => 'Instagram',
            'provider' => env('MESSAGING_INSTAGRAM_PROVIDER', 'log'),
            'connected_provider' => 'instagram',
            'conversations' => true,
            'templates' => false,
        ],
        'email' => [
            'label' => 'Email',
            'provider' => env('MESSAGING_EMAIL_PROVIDER', 'mail'),
            'connected_provider' => null,
            'conversations' => false,
            'templates' => false,
        ],
    ],

    'providers' => [
        'log' => ['class' => LogProvider::class, 'simulated' => true],
        'mail' => ['class' => MailProvider::class, 'simulated' => false],
        // Free-form messages need an inbound message within `window_hours` (Meta's customer service window).
        'meta_whatsapp' => ['class' => MetaWhatsAppProvider::class, 'simulated' => false, 'window_hours' => 24],
        'instagram' => ['class' => InstagramProvider::class, 'simulated' => false, 'window_hours' => 24],
    ],

    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),
        'instagram_url' => env('META_INSTAGRAM_URL', 'https://graph.instagram.com'),
        'timeout' => 15,
        'template_pages' => 10,
        // Sending and fetching files (up to 16 MB) takes longer than a text message.
        'media_timeout' => 60,
        // Files are only downloaded over HTTPS from these hosts (and their subdomains).
        'media_hosts' => ['fbsbx.com', 'fbcdn.net', 'cdninstagram.com', 'whatsapp.net'],
    ],

    'webhooks' => [
        // Per-IP requests per minute to /webhooks/meta/{key}.
        'rate_limit' => 600,
        'retention_days' => 14,
        'stuck_minutes' => 10,
        'max_payload_kb' => 512,
    ],

    // Whole-message matches, case-insensitive (WhatsApp opt-out guidance).
    'opt_out_keywords' => ['STOP', 'STOP ALL', 'UNSUBSCRIBE', 'CANCEL', 'OPT OUT', 'OPT-OUT', 'END', 'QUIT'],
    'opt_in_keywords' => ['START', 'UNSTOP', 'SUBSCRIBE', 'OPT IN', 'OPT-IN'],

    // Tenant setting `messaging.quiet_hours` overrides these. Times are in the tenant's timezone.
    'quiet_hours' => [
        'enabled' => false,
        'start' => '21:00',
        'end' => '09:00',
        'channels' => ['whatsapp', 'instagram', 'email'],
    ],

    'inbox' => [
        'per_page' => 25,
        'thread_limit' => 200,
        'poll_seconds' => 10,
        'reply_max' => 4096,
        'preview_length' => 120,
    ],

    'queue' => env('MESSAGING_QUEUE', 'messaging'),
    // Downloads of files contacts send (DownloadInboundMedia).
    'media_queue' => env('MESSAGING_MEDIA_QUEUE', 'media'),
    'tries' => 3,
    'backoff' => [60, 300],
    'stuck_queued_minutes' => 10,
    'stuck_sending_minutes' => 15,
    'dispatch_batch' => 500,

    'log_channel' => env('MESSAGING_LOG_CHANNEL'),

];
