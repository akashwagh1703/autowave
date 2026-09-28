<?php

namespace App\Http\Controllers\App;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Food\Actions\MarkKitchenReady;
use App\Http\Controllers\Controller;
use App\Http\Presenters\FoodPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kitchen screen (KOT): confirmed and ready orders with items still to prepare, oldest first.
 * Pending website orders appear once the team confirms them. The page reloads its tickets on a timer.
 */
class KitchenController extends Controller
{
    public const MAX_TICKETS = 60;

    public function index(): Response
    {
        $orders = Order::query()
            ->whereIn('status', [OrderStatus::Confirmed, OrderStatus::Ready])
            ->whereHas('items', fn (Builder $items) => $items->where('kitchen_status', OrderItem::KITCHEN_QUEUED))
            ->with(['items', 'table', 'customer'])
            ->get()
            ->map(fn (Order $order) => FoodPresenter::ticket($order))
            ->sortBy('queued_since')
            ->take(self::MAX_TICKETS)
            ->values();

        return Inertia::render('business/kitchen/Index', [
            'tickets' => $orders,
            'refreshSeconds' => (int) config('food.kitchen_refresh_seconds'),
            'serverTime' => now()->toIso8601String(),
        ]);
    }

    public function ready(Request $request, Order $order, MarkKitchenReady $markReady): RedirectResponse
    {
        $markReady->handle($order, $request->user());

        return back()->with('success', __('Order :number is ready.', ['number' => $order->reference()]));
    }
}
