<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a payment recorded by mistake. It is not a refund (refunds are not supported yet,
 * AW-043): the removal is kept on the timeline and in the audit log.
 */
class RemoveOrderPayment
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Order $order, OrderPayment $payment, ?User $actor = null): void
    {
        abort_unless($payment->order_id === $order->id, 404);

        DB::transaction(function () use ($order, $payment, $actor) {
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $payment->delete();
            RecordOrderPayment::refreshTotals($order);

            $metadata = [
                'number' => $order->number,
                'amount' => (string) $payment->amount,
                'method' => $payment->method,
            ];

            $this->recordActivity->handle('payment_removed', order: $order, actor: $actor, metadata: $metadata);
            $this->audit->log('order.payment_removed', $order, $metadata);
        });
    }
}
