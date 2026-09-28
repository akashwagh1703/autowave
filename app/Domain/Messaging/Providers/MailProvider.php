<?php

namespace App\Domain\Messaging\Providers;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Plain-text email through Laravel's configured mailer (MAIL_MAILER). */
class MailProvider implements MessagingProvider
{
    public function send(OutboundMessage $message): string
    {
        if ($message->channel !== 'email') {
            throw new InvalidArgumentException("The mail provider cannot send {$message->channel} messages.");
        }

        $subject = $message->subject ?: (string) Tenant::query()->whereKey($message->tenant_id)->value('name');

        Mail::raw($message->body, function (Message $mail) use ($message, $subject) {
            $mail->to($message->recipient, $message->recipient_name)->subject($subject);
        });

        return 'mail-'.Str::uuid();
    }
}
