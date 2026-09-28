<?php

use App\Domain\Messaging\Providers\LogProvider;
use App\Domain\Messaging\Providers\MailProvider;

/*
|--------------------------------------------------------------------------
| Outbound messaging (Phase 5; providers arrive in Phase 8)
|--------------------------------------------------------------------------
|
| Business code calls MessagingService, never a provider (master prompt §43).
| Each channel names its provider. `log` is a simulated provider: messages
| are recorded and marked sent, but nothing leaves the server. WhatsApp uses
| it until a real provider is connected in Phase 8.
|
*/

return [

    'channels' => [
        'whatsapp' => ['label' => 'WhatsApp', 'provider' => env('MESSAGING_WHATSAPP_PROVIDER', 'log')],
        'email' => ['label' => 'Email', 'provider' => env('MESSAGING_EMAIL_PROVIDER', 'mail')],
    ],

    'providers' => [
        'log' => ['class' => LogProvider::class, 'simulated' => true],
        'mail' => ['class' => MailProvider::class, 'simulated' => false],
    ],

    'queue' => env('MESSAGING_QUEUE', 'messaging'),
    'tries' => 3,
    'backoff' => [60, 300],
    'stuck_queued_minutes' => 10,
    'stuck_sending_minutes' => 15,
    'dispatch_batch' => 500,

    'log_channel' => env('MESSAGING_LOG_CHANNEL'),

];
