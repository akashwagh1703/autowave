<?php

namespace App\Domain\Website\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To a business's owners: a customer sent an enquiry or booked on the website. The text is
 * built when the event happens (inside the tenant), so the queued email needs no tenant context.
 */
class WebsiteActivityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, string>  $details  label => value, empty values already removed */
    public function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly string $intro,
        public readonly array $details,
        public readonly string $actionText,
        public readonly string $actionUrl,
        public readonly string $businessName,
    ) {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->line($this->intro);

        foreach ($this->details as $label => $value) {
            $mail->line("{$label}: {$value}");
        }

        return $mail
            ->action($this->actionText, $this->actionUrl)
            ->line(__('You get this email because email alerts are on for :business (Settings → Messaging).', ['business' => $this->businessName]));
    }
}
