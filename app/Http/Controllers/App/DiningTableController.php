<?php

namespace App\Http\Controllers\App;

use App\Domain\Commerce\Models\Order;
use App\Domain\Food\Actions\DeleteDiningTable;
use App\Domain\Food\Actions\SaveDiningTable;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Food\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Presenters\FoodPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** The floor: every table with its open order and its current or next reservation today. */
class DiningTableController extends Controller
{
    public function index(): Response
    {
        $tables = DiningTable::query()->ordered()->get();

        $openOrders = Order::query()->open()->whereNotNull('dining_table_id')->with(['items', 'customer'])->get()->groupBy('dining_table_id');
        $reservations = Reservation::query()->holding()
            ->whereNotNull('dining_table_id')
            ->where('ends_at', '>', now())
            ->where('reserved_at', '<', now()->addHours(12))
            ->with('customer')
            ->orderBy('reserved_at')
            ->get()
            ->groupBy('dining_table_id');

        return Inertia::render('business/tables/Index', [
            'tables' => $tables->map(fn (DiningTable $table) => [
                ...FoodPresenter::table($table),
                'orders' => ($openOrders[$table->id] ?? collect())->map(fn (Order $order) => [
                    'id' => $order->id,
                    'reference' => $order->reference(),
                    'status_label' => $order->statusLabel(),
                    'total' => (string) $order->total,
                    'balance' => $order->balance(),
                    'item_summary' => $order->itemSummary(3),
                    'queued' => $order->items->where('kitchen_status', 'queued')->count(),
                    'customer' => $order->customer?->name,
                ])->values(),
                'next_reservation' => ($next = ($reservations[$table->id] ?? collect())->first())
                    ? FoodPresenter::reservation($next)
                    : null,
            ]),
        ]);
    }

    public function store(Request $request, SaveDiningTable $saveTable): RedirectResponse
    {
        $table = $saveTable->handle($this->validated($request));

        return back()->with('success', __('Table :name added.', ['name' => $table->name]));
    }

    public function update(Request $request, DiningTable $table, SaveDiningTable $saveTable): RedirectResponse
    {
        $saveTable->handle($this->validated($request), $table);

        return back()->with('success', __('Table updated.'));
    }

    public function destroy(DiningTable $table, DeleteDiningTable $deleteTable): RedirectResponse
    {
        $deleteTable->handle($table);

        return back()->with('success', __('Table deleted.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->merge(['name' => Str::squish((string) $request->input('name'))]);

        return $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:40'],
            'seats' => ['required', 'integer', 'min:1', 'max:100'],
            'area' => ['nullable', 'string', 'max:40'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
