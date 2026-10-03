<?php

namespace App\Domain\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To every platform admin when the UPI ID, QR image or bank details change, so a misused admin account
 * cannot quietly redirect payments.
 */
class PaymentDetailsChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $fields */
    public function __construct(public readonly array $fields, public readonly string $changedBy, public readonly string $changedAt)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('AutoWave payment details were changed'))
            ->line(__('Changed: :fields.', ['fields' => implode(', ', $this->fields)]))
            ->line(__('By :user at :time.', ['user' => $this->changedBy, 'time' => $this->changedAt]))
            ->line(__('If you did not expect this, check Super Admin → Settings → Billing now: businesses pay to these details.'))
            ->action(__('Check payment details'), route('admin.settings'));
    }
}
