<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Billing\Models\BillingPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To platform admins: an owner reported a payment to check. */
class PaymentSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $paymentId, public readonly string $businessName)
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
        $payment = BillingPayment::withoutTenantScope()->with('plan')->findOrFail($this->paymentId);

        return (new MailMessage)
            ->subject(__('Payment to verify: :business', ['business' => $this->businessName]))
            ->line(__(':business reported a :method payment of :amount for the :plan plan (:period).', [
                'business' => $this->businessName,
                'method' => config("billing.methods.{$payment->method}"),
                'amount' => self::money($payment->total),
                'plan' => $payment->plan->name,
                'period' => strtolower(config("billing.periods.{$payment->period}.label")),
            ]))
            ->line(__('Reference / UTR: :reference', ['reference' => $payment->reference ?? '—']))
            ->line(__('Check that the money has arrived before approving.'))
            ->action(__('Review payments'), route('admin.billing.payments'));
    }

    public static function money(int $paise): string
    {
        return '₹'.number_format($paise / 100, $paise % 100 === 0 ? 0 : 2);
    }
}
