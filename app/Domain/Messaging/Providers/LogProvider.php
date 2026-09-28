<?php

namespace App\Domain\Messaging\Providers;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\OutboundMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Simulated provider: records that the message would have been sent. Nothing leaves the server.
 * The log line carries ids and a masked recipient, never the message text.
 */
class LogProvider implements MessagingProvider
{
    public function send(OutboundMessage $message): string
    {
        Log::channel(config('messaging.log_channel') ?: config('logging.default'))->info('Simulated outbound message', [
            'message_id' => $message->id,
            'tenant_id' => $message->tenant_id,
            'channel' => $message->channel,
            'recipient' => $message->maskedRecipient(),
            'length' => mb_strlen($message->body),
        ]);

        return 'sim-'.Str::uuid();
    }
}
