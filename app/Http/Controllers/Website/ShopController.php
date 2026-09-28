<?php

namespace App\Http\Controllers\Website;

use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Services\OnlineShop;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Online ordering from the public website (the products section's cart). */
class ShopController extends Controller
{
    public function quote(Request $request, OnlineShop $shop): JsonResponse
    {
        abort_unless($shop->isOpen(), 404);

        return response()->json($shop->quote($request->input('items'), $request->string('fulfilment')->toString() ?: null));
    }

    public function store(Request $request, TenantContext $context, OnlineShop $shop): RedirectResponse
    {
        abort_unless($shop->isOpen(), 404);

        if (filled($request->input(EnquiryController::HONEYPOT))) {
            Log::info('website.order_honeypot', ['tenant_id' => $context->id()]);

            return back();
        }

        $order = $shop->place($request->only(['name', 'phone', 'email', 'fulfilment', 'delivery_address', 'notes', 'items']));

        return back()->with('order_confirmation', [
            'number' => $order->reference(),
            'status' => $order->status->value,
            'fulfilment' => $order->fulfilment,
            'total' => (string) $order->total,
            'items' => $order->itemSummary(),
        ]);
    }
}
