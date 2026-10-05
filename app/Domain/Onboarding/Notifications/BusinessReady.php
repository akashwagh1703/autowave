<?php

namespace App\Domain\Onboarding\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the owner who just finished onboarding: the business is ready, with its links and first steps. */
class BusinessReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $businessName,
        public readonly ?string $websiteUrl,
        public readonly string $dashboardUrl,
        public readonly int $trialDays,
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
        $mail = (new MailMessage)
            ->subject(__(':business is ready on AutoWave', ['business' => $this->businessName]))
            ->greeting(__('Welcome to AutoWave, :name!', ['name' => $notifiable->name]))
            ->line(__(':business is set up. Your :days-day free trial has started: no card needed, and nothing renews automatically.', [
                'business' => $this->businessName,
                'days' => $this->trialDays,
            ]));

        if ($this->websiteUrl) {
            $mail->line(__('Your website: :url', ['url' => $this->websiteUrl]));
        }

        return $mail
            ->line(__('Good first steps:'))
            ->line(__('1. Website: add your photos, services or products, then publish.'))
            ->line(__('2. Settings → Messaging: connect WhatsApp so enquiries and replies reach you.'))
            ->line(__('3. Share your website link on WhatsApp, Instagram and Google.'))
            ->action(__('Open your dashboard'), $this->dashboardUrl)
            ->line(__('Questions? Just reply to this email.'))
            ->salutation(__('Team AutoWave'));
    }
}
