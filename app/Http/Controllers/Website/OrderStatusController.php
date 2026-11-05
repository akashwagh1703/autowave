<?php

namespace App\Http\Controllers\Website;

use App\Domain\Commerce\Models\Order;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Public signed order status page (optional payment-request QR / status link). */
class OrderStatusController extends Controller
{
    public function __invoke(Order $order, TenantContext $context): Response
    {
        $order->loadMissing(['items', 'customer']);
        $profile = $context->setting('business_profile', []);
        $profile = is_array($profile) ? $profile : [];

        return Inertia::render('website/OrderStatus', [
            'business' => [
                'name' => $context->tenant()->name,
                'phone' => $profile['phone'] ?? null,
                'address' => $profile['address'] ?? null,
            ],
            'order' => [
                'number' => $order->reference(),
                'status' => $order->status->value,
                'status_label' => $order->statusLabel(),
                'fulfilment' => config("commerce.fulfilment.{$order->fulfilment}.label", $order->fulfilment),
                'items' => $order->itemSummary(20),
                'total' => number_format((float) $order->total, 2),
                'balance' => number_format((float) $order->balance(), 2),
                'payment_status' => $order->payment_status->value,
                'customer' => $order->customer?->name,
                'delivery_address' => $order->delivery_address,
            ],
        ]);
    }
}
