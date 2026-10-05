<?php

namespace App\Domain\Marketing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the person who asked for a demo: we got your request, here is what happens next. */
class DemoRequestReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $businessName,
        public readonly string $phone,
        public readonly string $registerUrl,
        public readonly ?string $whatsappUrl,
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
            ->subject(__('We got your demo request'))
            ->greeting(__('Hi :name,', ['name' => $this->name]))
            ->line(__('Thanks for asking for an AutoWave demo for :business.', ['business' => $this->businessName]))
            ->line(__('We will call or WhatsApp you on :phone within one working day to fix a time that suits you. The demo takes about 20 minutes and is free.', ['phone' => $this->phone]))
            ->line(__('Want to look around first? Start a free trial: no card needed and nothing renews automatically.'))
            ->action(__('Start free trial'), $this->registerUrl);

        if ($this->whatsappUrl) {
            $mail->line(__('Questions before then? Message us on WhatsApp: :url', ['url' => $this->whatsappUrl]));
        }

        return $mail->salutation(__('Team AutoWave'));
    }
}
