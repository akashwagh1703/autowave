<?php

namespace App\Http\Controllers\App;

use App\Domain\Commerce\Actions\ChangeOrderStatus;
use App\Domain\Commerce\Actions\RecordOrderPayment;
use App\Domain\Commerce\Actions\RemoveOrderPayment;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderPayment;
use App\Http\Controllers\Controller;
use App\Support\TenantTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Lifecycle actions on existing orders: status changes and recorded payments (`orders.update`). */
class OrderActionController extends Controller
{
    private const STATUS_TARGETS = ['confirmed', 'ready', 'completed', 'cancelled'];

    public function status(Request $request, Order $order, ChangeOrderStatus $changeStatus): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUS_TARGETS)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = OrderStatus::from($validated['status']);
        $changeStatus->handle($order, $status, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', match ($status) {
            OrderStatus::Confirmed => __('Order :number confirmed.', ['number' => $order->reference()]),
            OrderStatus::Ready => __('Order :number marked :status.', ['number' => $order->reference(), 'status' => strtolower($status->labelFor($order->fulfilment))]),
            OrderStatus::Completed => __('Order :number completed.', ['number' => $order->reference()]),
            OrderStatus::Cancelled => __('Order :number cancelled.', ['number' => $order->reference()]),
            OrderStatus::Pending => __('Order updated.'),
        });
    }

    public function storePayment(Request $request, Order $order, RecordOrderPayment $recordPayment): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.config('commerce.limits.max_price')],
            'method' => ['required', Rule::in(array_keys(config('commerce.payment_methods')))],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
        ], ['method.required' => __('Choose how the customer paid.')]);

        $paidAt = filled($validated['paid_at'] ?? null) ? TenantTime::parse($validated['paid_at']) : null;

        if ($paidAt?->isFuture()) {
            throw ValidationException::withMessages(['paid_at' => __('The payment date cannot be in the future.')]);
        }

        $recordPayment->handle($order, [...$validated, 'paid_at' => $paidAt], $request->user());

        return back()->with('success', __('Payment recorded.'));
    }

    public function destroyPayment(Request $request, Order $order, OrderPayment $payment, RemoveOrderPayment $removePayment): RedirectResponse
    {
        $removePayment->handle($order, $payment, $request->user());

        return back()->with('success', __('Payment removed.'));
    }
}
