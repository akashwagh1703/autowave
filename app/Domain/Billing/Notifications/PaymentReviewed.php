<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Billing\Models\BillingInvoice;
use App\Domain\Billing\Models\BillingPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the business's owners: their payment was approved (with the invoice) or rejected (with the reason). */
class PaymentReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $paymentId)
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
        $amount = PaymentSubmitted::money($payment->total);

        if ($payment->status === BillingPayment::REJECTED) {
            return (new MailMessage)
                ->subject(__('We could not confirm your AutoWave payment'))
                ->line(__('Your payment of :amount for the :plan plan could not be confirmed.', ['amount' => $amount, 'plan' => $payment->plan->name]))
                ->line(__('Reason: :reason', ['reason' => $payment->rejection_reason]))
                ->line(__('You can submit the payment details again from Billing.'))
                ->action(__('Open Billing'), route('billing.show'));
        }

        $invoice = BillingInvoice::withoutTenantScope()->where('billing_payment_id', $payment->id)->first();
        $message = (new MailMessage)
            ->subject(__('Payment received: :plan plan', ['plan' => $payment->plan->name]))
            ->line(__('Thank you. We received :amount for the :plan plan.', ['amount' => $amount, 'plan' => $payment->plan->name]))
            ->line(__('Paid until :date.', ['date' => $payment->covers_until?->timezone('Asia/Kolkata')->format('j M Y')]));

        return $invoice
            ? $message->line(__('Invoice :number', ['number' => $invoice->number]))->action(__('View invoice'), route('billing.invoices.show', $invoice->id))
            : $message->action(__('Open Billing'), route('billing.show'));
    }
}
