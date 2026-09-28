<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place stock changes. Every change locks the product row (SELECT … FOR UPDATE), checks
 * the result stays at or above zero, updates stock_quantity and writes a stock_movements row with
 * the new balance. The products_valid check constraint backs up the "never below zero" rule.
 */
class StockLedger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Change a product's stock by `change` (negative takes stock). Must run inside a transaction
     * when combined with other writes; opens its own otherwise.
     */
    public function move(Product $product, int $change, string $reason, ?User $actor = null, ?Order $order = null, ?string $note = null, string $field = 'quantity'): StockMovement
    {
        if ($change === 0) {
            throw ValidationException::withMessages([$field => __('Enter a quantity other than zero.')]);
        }

        return DB::transaction(function () use ($product, $change, $reason, $actor, $order, $note, $field) {
            $locked = Product::withTrashed()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $balance = $locked->stock_quantity + $change;

            if ($balance < 0) {
                throw ValidationException::withMessages([$field => $locked->stock_quantity > 0
                    ? __('Only :count of :name left in stock.', ['count' => $locked->stock_quantity, 'name' => $locked->name])
                    : __(':name is out of stock.', ['name' => $locked->name])]);
            }

            if ($balance > (int) config('commerce.limits.max_stock')) {
                throw ValidationException::withMessages([$field => __('Stock cannot be more than :max.', ['max' => config('commerce.limits.max_stock')])]);
            }

            $locked->forceFill(['stock_quantity' => $balance])->save();
            $product->setAttribute('stock_quantity', $balance);

            return StockMovement::query()->create([
                'product_id' => $locked->id,
                'quantity_change' => $change,
                'balance_after' => $balance,
                'reason' => $reason,
                'order_id' => $order?->id,
                'note' => filled($note) ? trim($note) : null,
                'created_by_user_id' => $actor?->id,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * A manual adjustment from the product page: add, remove or set the count.
     *
     * @param  array{mode: string, quantity: int, reason: string, note?: ?string}  $data
     */
    public function adjust(Product $product, array $data, ?User $actor = null): ?StockMovement
    {
        if (! $product->track_stock) {
            throw ValidationException::withMessages(['quantity' => __('Turn on stock tracking for this product first.')]);
        }

        if (! (config("commerce.stock_reasons.{$data['reason']}.manual") ?? false)) {
            throw ValidationException::withMessages(['reason' => __('Choose a reason.')]);
        }

        $quantity = (int) $data['quantity'];

        $movement = DB::transaction(function () use ($product, $data, $quantity, $actor) {
            $current = Product::query()->whereKey($product->id)->lockForUpdate()->value('stock_quantity');
            $change = match ($data['mode']) {
                'add' => $quantity,
                'remove' => -$quantity,
                'set' => $quantity - (int) $current,
                default => throw ValidationException::withMessages(['mode' => __('Choose how to change the stock.')]),
            };

            if ($change === 0) {
                return null;
            }

            return $this->move($product, $change, $data['reason'], $actor, note: $data['note'] ?? null);
        });

        if ($movement) {
            $this->audit->log('product.stock_adjusted', $product, [
                'change' => $movement->quantity_change,
                'balance' => $movement->balance_after,
                'reason' => $movement->reason,
            ]);
        }

        return $movement;
    }
}
