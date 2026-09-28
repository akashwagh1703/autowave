<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Events\OrderPaid;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderPayment;
use App\Domain\Commerce\Services\OrderPricing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money received for an order (manual payments, no gateway). A payment cannot be more
 * than the balance still due, and cancelled orders take no payments. amount_paid and
 * payment_status are recomputed from the payment rows under a lock on the order.
 */
class RecordOrderPayment
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{amount: numeric-string|float|int, method: string, reference?: ?string, paid_at?: ?\DateTimeInterface}  $data
     */
    public function handle(Order $order, array $data, ?User $actor = null, string $field = 'amount'): OrderPayment
    {
        $amount = OrderPricing::money($data['amount'] ?? 0);

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages([$field => __('Enter an amount greater than zero.')]);
        }

        if (! array_key_exists($data['method'] ?? '', config('commerce.payment_methods'))) {
            throw ValidationException::withMessages(['method' => __('Choose how the customer paid.')]);
        }

        return DB::transaction(function () use ($order, $data, $actor, $amount, $field) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Cancelled) {
                throw ValidationException::withMessages([$field => __('A cancelled order cannot take payments.')]);
            }

            $balance = $locked->balance();

            if (bccomp($amount, $balance, 2) > 0) {
                throw ValidationException::withMessages([$field => bccomp($balance, '0', 2) <= 0
                    ? __('This order is already fully paid.')
                    : __('The amount cannot be more than the balance due (:balance).', ['balance' => $balance])]);
            }

            $payment = $locked->payments()->create([
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => filled($data['reference'] ?? null) ? trim($data['reference']) : null,
                'paid_at' => $data['paid_at'] ?? now(),
                'recorded_by_user_id' => $actor?->id,
            ]);

            $wasPaid = $locked->payment_status === PaymentStatus::Paid;
            self::refreshTotals($order);

            $this->recordActivity->handle('payment_recorded', order: $order, actor: $actor, metadata: [
                'number' => $order->number,
                'amount' => $amount,
                'method' => $payment->method,
                'method_label' => $payment->methodLabel(),
            ]);

            $this->audit->log('order.payment_recorded', $order, [
                'number' => $order->number,
                'amount' => $amount,
                'method' => $payment->method,
            ]);

            if (! $wasPaid && $order->payment_status === PaymentStatus::Paid) {
                OrderPaid::dispatch($order);
            }

            return $payment;
        });
    }

    /** Recomputes amount_paid and payment_status from the payment rows (order must be locked). */
    public static function refreshTotals(Order $order): void
    {
        $paid = number_format((float) OrderPayment::query()->where('order_id', $order->id)->sum('amount'), 2, '.', '');

        $order->forceFill([
            'amount_paid' => $paid,
            'payment_status' => PaymentStatus::for($paid, number_format((float) $order->total, 2, '.', '')),
        ])->save();
    }
}
