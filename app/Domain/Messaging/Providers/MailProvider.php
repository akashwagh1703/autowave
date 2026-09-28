<?php

namespace App\Domain\Messaging\Providers;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Support\MessagingSettings;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Plain-text email through Laravel's configured mailer (MAIL_MAILER). The from address is the platform's;
 * the from name and reply-to come from the tenant's messaging settings.
 */
class MailProvider implements MessagingProvider
{
    public function __construct(private readonly MessagingSettings $settings) {}

    public function send(OutboundMessage $message): string
    {
        if ($message->channel !== 'email') {
            throw new InvalidArgumentException("The mail provider cannot send {$message->channel} messages.");
        }

        $tenantName = (string) Tenant::query()->whereKey($message->tenant_id)->value('name');
        $subject = $message->subject ?: $tenantName;
        $sender = $this->settings->email();

        Mail::raw($message->body, function (Message $mail) use ($message, $subject, $sender, $tenantName) {
            $mail->to($message->recipient, $message->recipient_name)->subject($subject);
            $mail->from((string) config('mail.from.address'), $sender['from_name'] ?? ($tenantName ?: config('mail.from.name')));

            if ($sender['reply_to']) {
                $mail->replyTo($sender['reply_to']);
            }
        });

        return 'mail-'.Str::uuid();
    }
}
