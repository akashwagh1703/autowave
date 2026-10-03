<?php

namespace App\Domain\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the business's owners (billing:sweep): the trial or paid period ends in a few days, has ended, the
 * business became read-only, or was locked.
 */
class SubscriptionReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public const ENDING = 'ending';

    public const ENDED = 'ended';

    public const READ_ONLY = 'read_only';

    public const LOCKED = 'locked';

    public function __construct(
        public readonly string $type,
        public readonly string $businessName,
        public readonly bool $trial,
        public readonly string $endsOn,
        public readonly int $days = 0,
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
        $what = $this->trial ? __('free trial') : __('plan');
        $message = (new MailMessage)->action(__('Choose a plan and pay'), route('billing.show'));

        return match ($this->type) {
            self::ENDING => $message
                ->subject(__('Your AutoWave :what ends in :days days', ['what' => $what, 'days' => $this->days]))
                ->line(__('The :what for :business ends on :date.', ['what' => $what, 'business' => $this->businessName, 'date' => $this->endsOn]))
                ->line(__('Pay before then to keep everything running without a break.')),
            self::ENDED => $message
                ->subject(__('Your AutoWave :what has ended', ['what' => $what]))
                ->line(__('The :what for :business ended on :date. Everything keeps working for :grace more days.', ['what' => $what, 'business' => $this->businessName, 'date' => $this->endsOn, 'grace' => config('billing.grace_days')])),
            self::READ_ONLY => $message
                ->subject(__(':business is now read-only on AutoWave', ['business' => $this->businessName]))
                ->line(__('Your team can still sign in and see everything, but changes, messages and automations are paused until you pay.'))
                ->line(__('Your website stays online.')),
            default => $message
                ->subject(__(':business is locked on AutoWave', ['business' => $this->businessName]))
                ->line(__('Only the Billing page can be opened and your website is offline. Nothing has been deleted: paying restores everything straight away.')),
        };
    }
}
