<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Events\OrderCompleted;
use App\Domain\Commerce\Events\OrderConfirmed;
use App\Domain\Commerce\Events\OrderCreated;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Services\Coupons;
use App\Domain\Commerce\Services\OrderPricing;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Places an order, from the team (source `manual`) or the website (source `website`). ADR-017:
 * - prices come from the products (OrderPricing), never from the request;
 * - stock of tracked products is taken at once, under row locks (StockLedger), so two orders can
 *   never sell the same last item; cancelling puts it back;
 * - numbers are sequential per tenant: the tenant row is locked while the next number is taken,
 *   and unique (tenant_id, number) backs this up.
 */
class PlaceOrder
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CommerceSettings $settings,
        private readonly OrderPricing $pricing,
        private readonly StockLedger $ledger,
        private readonly CreateCustomer $createCustomer,
        private readonly RecordActivity $recordActivity,
        private readonly RecordOrderPayment $recordPayment,
        private readonly Coupons $coupons,
    ) {}

    /**
     * @param  array{
     *     customer_id?: ?int,
     *     customer?: ?array{name: string, phone?: ?string, email?: ?string},
     *     items: list<array{product_id: int, quantity: int}>,
     *     fulfilment: string,
     *     delivery_address?: ?string,
     *     delivery_fee?: numeric-string|float|int|null,
     *     discount?: numeric-string|float|int|null,
     *     notes?: ?string,
     *     source?: ?string,
     *     completed?: bool,
     *     payment?: ?array{amount: numeric-string|float|int, method: string, reference?: ?string},
     * }  $data  website orders ignore discount, delivery_fee, completed and payment
     */
    public function handle(array $data, ?User $actor = null): Order
    {
        $online = ($data['source'] ?? 'manual') === 'website';
        $items = OrderPricing::normalize($data['items'] ?? null);
        $fulfilment = $this->fulfilment($data['fulfilment'] ?? null, $online);
        $address = filled($data['delivery_address'] ?? null) ? trim($data['delivery_address']) : null;

        if ($fulfilment === 'delivery' && $address === null) {
            throw ValidationException::withMessages(['delivery_address' => __('Enter the delivery address.')]);
        }

        $table = null;

        if ($fulfilment === 'dine_in' && ! empty($data['dining_table_id'])) {
            $table = DiningTable::query()->active()->find($data['dining_table_id'])
                ?? throw ValidationException::withMessages(['dining_table_id' => __('Choose an active table.')]);
        }

        return DB::transaction(function () use ($data, $actor, $online, $items, $fulfilment, $address, $table) {
            $priced = $this->pricing->strict($items);
            $subtotal = $priced['subtotal'];

            if ($online && ($min = $this->settings->online()['min_order']) !== null && bccomp($subtotal, $min, 2) < 0) {
                throw ValidationException::withMessages(['items' => __('The minimum order is :amount.', ['amount' => $min])]);
            }

            $coupon = $this->coupons->resolve($data['coupon_code'] ?? null, $subtotal, $online);
            $discount = bcadd($online ? '0.00' : OrderPricing::money($data['discount'] ?? 0), $coupon['discount'] ?? '0.00', 2);

            if (bccomp($discount, $subtotal, 2) > 0) {
                throw ValidationException::withMessages(['discount' => __('The discount cannot be more than the order subtotal.')]);
            }

            if ($coupon) {
                $this->coupons->claim($coupon['coupon']);
            }

            $deliveryFee = match (true) {
                $fulfilment !== 'delivery' => '0.00',
                $online => $this->settings->deliveryFeeFor($subtotal),
                default => OrderPricing::money($data['delivery_fee'] ?? 0),
            };

            $total = bcadd(bcsub($subtotal, $discount, 2), $deliveryFee, 2);
            $status = $this->initialStatus($online, (bool) ($data['completed'] ?? false));
            $customer = $this->resolveCustomer($data, $actor, $online, $fulfilment === 'dine_in');

            $order = Order::query()->create([
                'number' => $this->nextNumber(),
                'customer_id' => $customer?->id,
                'dining_table_id' => $table?->id,
                'status' => $status,
                'source' => $online ? 'website' : 'manual',
                'fulfilment' => $fulfilment,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'coupon_id' => $coupon['coupon']->id ?? null,
                'coupon_code' => $coupon['coupon']->code ?? null,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'amount_paid' => '0.00',
                'payment_status' => PaymentStatus::for('0.00', $total),
                'delivery_address' => $fulfilment === 'delivery' ? $address : null,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'confirmed_at' => $status !== OrderStatus::Pending ? now() : null,
                'completed_at' => $status === OrderStatus::Completed ? now() : null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->addItems($order, $priced['lines'], $actor);

            if ($customer && $fulfilment === 'delivery' && blank($customer->address)) {
                $customer->forceFill(['address' => $address])->save();
            }

            $order->setRelation('customer', $customer)->setRelation('table', $table);

            $this->recordActivity->handle('order_placed', order: $order, actor: $actor, metadata: [
                ...self::summary($order),
                ...($online ? ['source' => 'website'] : []),
            ]);

            OrderCreated::dispatch($order);

            if ($status !== OrderStatus::Pending) {
                OrderConfirmed::dispatch($order);
            }

            if ($status === OrderStatus::Completed) {
                OrderCompleted::dispatch($order);
            }

            if (! $online && is_array($data['payment'] ?? null) && (float) ($data['payment']['amount'] ?? 0) > 0) {
                $this->recordPayment->handle($order, $data['payment'], $actor, field: 'payment.amount');
            }

            return $order;
        });
    }

    /**
     * Timeline and automation summary of an order at the time of the entry.
     *
     * @return array<string, mixed>
     */
    public static function summary(Order $order): array
    {
        return [
            'number' => $order->number,
            'total' => (string) $order->total,
            'items' => $order->itemSummary(3),
            'fulfilment' => $order->fulfilment,
            ...($order->dining_table_id ? ['table' => $order->table?->name] : []),
            ...($order->coupon_code ? ['coupon' => $order->coupon_code] : []),
        ];
    }

    /**
     * Creates order lines, taking stock of tracked products. For food-engine tenants the lines join
     * the kitchen queue, unless the order is already completed (a counter sale).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function addItems(Order $order, array $lines, ?User $actor): void
    {
        // Take stock in product id order so concurrent orders lock rows in the same order.
        usort($lines, fn (array $a, array $b) => $a['product_id'] <=> $b['product_id']);

        $kitchen = $this->context->hasEngine('food') && $order->status !== OrderStatus::Completed;

        foreach ($lines as $line) {
            $product = $line['product'];

            if ($product->track_stock) {
                $this->ledger->move($product, -$line['quantity'], 'sale', $actor, $order, field: 'items');
            }

            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'line_total' => $line['line_total'],
                'stock_deducted' => $product->track_stock,
                'notes' => filled($line['notes'] ?? null) ? trim($line['notes']) : null,
                'kitchen_status' => $kitchen ? OrderItem::KITCHEN_QUEUED : null,
                'added_at' => now(),
            ]);
        }

        $order->load('items');
    }

    private function nextNumber(): int
    {
        Tenant::query()->whereKey($this->context->id())->lockForUpdate()->first();

        return max(((int) Order::query()->max('number')) + 1, Order::FIRST_NUMBER);
    }

    private function fulfilment(?string $requested, bool $online): string
    {
        $allowed = $online
            ? $this->settings->onlineFulfilment()
            : array_keys(self::staffFulfilment($this->context));

        if (! $requested || ! in_array($requested, $allowed, true)) {
            throw ValidationException::withMessages(['fulfilment' => $online
                ? __('Choose pickup or delivery.')
                : __('Choose how the order reaches the customer.')]);
        }

        return $requested;
    }

    private function initialStatus(bool $online, bool $completed): OrderStatus
    {
        if ($online) {
            return $this->settings->online()['auto_confirm'] ? OrderStatus::Confirmed : OrderStatus::Pending;
        }

        return $completed ? OrderStatus::Completed : OrderStatus::Confirmed;
    }

    /**
     * Fulfilment methods the team can choose: all of config('commerce.fulfilment') except those needing
     * an engine the tenant does not have (dine-in needs the food engine).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function staffFulfilment(TenantContext $context): array
    {
        return array_filter(config('commerce.fulfilment'), fn (array $method) => ! isset($method['engine']) || $context->hasEngine($method['engine']));
    }

    /**
     * An existing customer by id, or the inline customer: reused when the phone number matches a
     * customer, otherwise created. A dine-in order may have no customer (a walk-in).
     */
    private function resolveCustomer(array $data, ?User $actor, bool $online, bool $walkIn = false): ?Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::query()->find($data['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Choose a valid customer.')]);
        }

        $inline = $data['customer'] ?? null;

        if ($walkIn && ! $online && (! is_array($inline) || blank($inline['name'] ?? null))) {
            return null;
        }

        if (! is_array($inline) || blank($inline['name'] ?? null)) {
            throw ValidationException::withMessages(['customer_id' => __('Choose a customer or enter their details.')]);
        }

        return CreateCustomer::duplicateOf($inline['phone'] ?? null)
            ?? $this->createCustomer->handle([
                'name' => $inline['name'],
                'phone' => $inline['phone'] ?? null,
                'email' => $inline['email'] ?? null,
            ], $actor, ['via' => $online ? 'online_order' : 'order']);
    }
}
