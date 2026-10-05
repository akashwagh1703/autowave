<?php

namespace App\Domain\Marketing\Notifications;

use App\Domain\Marketing\Models\DemoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To platform admins: someone asked for a demo on the marketing site. */
class DemoRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $demoRequestId)
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
        $request = DemoRequest::query()->findOrFail($this->demoRequestId);

        $mail = (new MailMessage)
            ->subject(__('Demo request: :business', ['business' => $request->business_name]))
            ->line(__(':name from :business asked for a demo. Contact them soon.', ['name' => $request->name, 'business' => $request->business_name]))
            ->line(__('Phone: :phone', ['phone' => $request->phone]));

        foreach (array_filter([
            __('Email') => $request->email,
            __('Industry') => $request->industry,
            __('City') => $request->city,
            __('Message') => $request->message,
        ]) as $label => $value) {
            $mail->line("{$label}: {$value}");
        }

        return $mail->action(__('Open demo requests'), route('admin.demo-requests.index'));
    }
}
