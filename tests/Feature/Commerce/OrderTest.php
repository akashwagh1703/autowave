<?php

namespace Tests\Feature\Commerce;

use App\Domain\Activity\Models\Activity;
use App\Domain\Commerce\Actions\ChangeOrderStatus;
use App\Domain\Commerce\Actions\RecordOrderPayment;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Events\OrderCancelled;
use App\Domain\Commerce\Events\OrderCompleted;
use App\Domain\Commerce\Events\OrderConfirmed;
use App\Domain\Commerce\Events\OrderCreated;
use App\Domain\Commerce\Events\OrderPaid;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderPayment;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\StockMovement;
use App\Domain\Customer\Models\Customer;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Product $serum;

    private Product $shampoo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->owner = $this->ownerOf($this->tenant);
        $this->serum = $this->makeProduct($this->tenant, ['name' => 'Hair serum', 'sku' => 'SER-1', 'price' => '450.00'], stock: 10);
        $this->shampoo = $this->makeProduct($this->tenant, ['name' => 'Shampoo', 'price' => '199.50']);
    }

    private function orderPayload(array $overrides = []): array
    {
        return [
            'customer' => ['name' => 'Meera Joshi', 'phone' => '99887 76655', 'email' => ''],
            'items' => [
                ['product_id' => $this->serum->id, 'quantity' => 2, 'price' => '1.00'],
                ['product_id' => $this->shampoo->id, 'quantity' => 1],
            ],
            'fulfilment' => 'in_store',
            'discount' => '',
            'delivery_fee' => '',
            'notes' => 'Gift wrap',
            ...$overrides,
        ];
    }

    public function test_staff_create_an_order_priced_from_the_products(): void
    {
        $this->actingAs($this->owner);

        $response = $this->post($this->appUrl('/orders'), $this->orderPayload(['discount' => '99.50']));

        $order = $this->inTenant($this->tenant, fn () => Order::query()->with(['items', 'customer'])->sole());
        $response->assertSessionHasNoErrors()->assertRedirect($this->appUrl("/orders/{$order->id}"));

        $this->assertSame(1001, $order->number);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame('manual', $order->source);
        // A price sent by the browser is ignored.
        $this->assertSame(['1099.50', '99.50', '0.00', '1000.00'], [(string) $order->subtotal, (string) $order->discount, (string) $order->delivery_fee, (string) $order->total]);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame([['Hair serum', 'SER-1', '450.00', 2, '900.00', true], ['Shampoo', null, '199.50', 1, '199.50', false]], $order->items
            ->map(fn ($item) => [$item->product_name, $item->sku, (string) $item->unit_price, $item->quantity, (string) $item->line_total, $item->stock_deducted])->all());
        $this->assertSame('Meera Joshi', $order->customer->name);
        $this->assertSame('Gift wrap', $order->notes);
        $this->assertSame($this->owner->id, $order->created_by_user_id);

        $this->assertSame(8, $this->stockOf($this->tenant, $this->serum));
        $sale = $this->inTenant($this->tenant, fn () => StockMovement::query()->where('reason', 'sale')->sole());
        $this->assertSame([-2, 8, $order->id], [$sale->quantity_change, $sale->balance_after, $sale->order_id]);

        $activity = $this->inTenant($this->tenant, fn () => Activity::query()->where('type', 'order_placed')->sole());
        $this->assertSame($order->id, $activity->order_id);
        $this->assertSame($order->customer_id, $activity->customer_id);
        $this->assertSame('2 × Hair serum, 1 × Shampoo', $activity->metadata['items']);
    }

    public function test_order_numbers_are_sequential_per_business(): void
    {
        $other = $this->createTenant('Other Salon');
        $theirs = $this->makeProduct($other);

        $this->assertSame(1001, $this->placeOrder($this->tenant, [[$this->shampoo, 1]])->number);
        $this->assertSame(1001, $this->placeOrder($other, [[$theirs, 1]])->number);
        $this->assertSame(1002, $this->placeOrder($this->tenant, [[$this->shampoo, 1]])->number);
    }

    public function test_an_order_cannot_sell_more_than_the_stock(): void
    {
        $this->actingAs($this->owner);

        $this->post($this->appUrl('/orders'), $this->orderPayload(['items' => [['product_id' => $this->serum->id, 'quantity' => 11]]]))
            ->assertSessionHasErrors(['items' => 'Only 10 of Hair serum left in stock.']);

        // Duplicate lines are merged before the check.
        $this->post($this->appUrl('/orders'), $this->orderPayload(['items' => [
            ['product_id' => $this->serum->id, 'quantity' => 6],
            ['product_id' => $this->serum->id, 'quantity' => 5],
        ]]))->assertSessionHasErrors('items');

        $this->assertSame(10, $this->stockOf($this->tenant, $this->serum));
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Order::query()->count()));
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Customer::query()->count()));
    }

    public function test_order_rules_are_enforced(): void
    {
        $this->actingAs($this->owner);
        $inactive = $this->makeProduct($this->tenant, ['is_active' => false]);

        $this->post($this->appUrl('/orders'), $this->orderPayload(['items' => []]))->assertSessionHasErrors('items');
        $this->post($this->appUrl('/orders'), $this->orderPayload(['items' => [['product_id' => $inactive->id, 'quantity' => 1]]]))
            ->assertSessionHasErrors(['items' => 'One of the products is no longer available.']);
        $this->post($this->appUrl('/orders'), $this->orderPayload(['items' => [['product_id' => $this->shampoo->id, 'quantity' => 0]]]))->assertSessionHasErrors();
        $this->post($this->appUrl('/orders'), $this->orderPayload(['fulfilment' => 'teleport']))->assertSessionHasErrors('fulfilment');
        $this->post($this->appUrl('/orders'), $this->orderPayload(['fulfilment' => 'delivery', 'delivery_address' => '']))->assertSessionHasErrors('delivery_address');
        $this->post($this->appUrl('/orders'), $this->orderPayload(['discount' => '5000']))->assertSessionHasErrors('discount');
        $this->post($this->appUrl('/orders'), $this->orderPayload(['customer' => ['name' => '']]))->assertSessionHasErrors();

        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Order::query()->count()));
    }

    public function test_a_counter_sale_is_completed_and_paid_in_one_step(): void
    {
        Event::fake([OrderCreated::class, OrderConfirmed::class, OrderCompleted::class, OrderPaid::class]);
        $this->actingAs($this->owner);
        $customer = $this->makeCustomer($this->tenant, ['name' => 'Asha']);

        $this->post($this->appUrl('/orders'), $this->orderPayload([
            'customer' => null,
            'customer_id' => $customer->id,
            'items' => [['product_id' => $this->shampoo->id, 'quantity' => 2]],
            'completed' => true,
            'payment' => ['amount' => '399.00', 'method' => 'upi', 'reference' => 'UPI-123'],
        ]))->assertSessionHasNoErrors();

        $order = $this->inTenant($this->tenant, fn () => Order::query()->with('payments')->sole());
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('399.00', (string) $order->amount_paid);
        $this->assertSame(['399.00', 'upi', 'UPI-123'], [(string) $order->payments[0]->amount, $order->payments[0]->method, $order->payments[0]->reference]);

        foreach ([OrderCreated::class, OrderConfirmed::class, OrderCompleted::class, OrderPaid::class] as $event) {
            Event::assertDispatched($event);
        }
    }

    public function test_delivery_orders_keep_the_address_and_fill_the_customer_address(): void
    {
        $this->actingAs($this->owner);

        $this->post($this->appUrl('/orders'), $this->orderPayload([
            'fulfilment' => 'delivery',
            'delivery_address' => '12 MG Road, Pune',
            'delivery_fee' => '40',
        ]))->assertSessionHasNoErrors();

        $order = $this->inTenant($this->tenant, fn () => Order::query()->with('customer')->sole());
        $this->assertSame(['40.00', '1139.50'], [(string) $order->delivery_fee, (string) $order->total]);
        $this->assertSame('12 MG Road, Pune', $order->delivery_address);
        $this->assertSame('12 MG Road, Pune', $order->customer->address);
    }

    public function test_the_lifecycle_moves_forward_and_final_states_stay_final(): void
    {
        $this->actingAs($this->owner);
        $order = $this->placeOrder($this->tenant, [[$this->serum, 1]], ['fulfilment' => 'pickup']);

        $this->patch($this->appUrl("/orders/{$order->id}/status"), ['status' => 'ready'])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Ready, $this->inTenant($this->tenant, fn () => $order->fresh()->status));
        $this->assertSame('Ready for pickup', $this->inTenant($this->tenant, fn () => $order->fresh()->statusLabel()));

        $this->patch($this->appUrl("/orders/{$order->id}/status"), ['status' => 'confirmed'])->assertSessionHasErrors('status');
        $this->patch($this->appUrl("/orders/{$order->id}/status"), ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->patch($this->appUrl("/orders/{$order->id}/status"), ['status' => 'cancelled'])->assertSessionHasErrors('status');
        $this->patch($this->appUrl("/orders/{$order->id}/status"), ['status' => 'pending'])->assertSessionHasErrors('status');

        $fresh = $this->inTenant($this->tenant, fn () => $order->fresh());
        $this->assertSame(OrderStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->ready_at);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame(['order_placed', 'order_ready', 'order_completed'], $this->inTenant($this->tenant, fn () => Activity::query()->where('order_id', $order->id)->orderBy('id')->pluck('type')->all()));
    }

    public function test_cancelling_puts_the_stock_back_once(): void
    {
        Event::fake([OrderCancelled::class]);
        $this->actingAs($this->owner);
        $order = $this->placeOrder($this->tenant, [[$this->serum, 3], [$this->shampoo, 1]]);
        $this->assertSame(7, $this->stockOf($this->tenant, $this->serum));

        // Even a product deleted since the order gets its stock back.
        $this->inTenant($this->tenant, fn () => $this->serum->delete());

        $this->patch($this->appUrl("/orders/{$order->id}/status"), ['status' => 'cancelled', 'reason' => 'Customer changed their mind'])->assertSessionHasNoErrors();

        $this->assertSame(10, $this->stockOf($this->tenant, $this->serum));
        $fresh = $this->inTenant($this->tenant, fn () => $order->fresh(['items']));
        $this->assertSame(OrderStatus::Cancelled, $fresh->status);
        $this->assertSame('Customer changed their mind', $fresh->cancellation_reason);
        $this->assertFalse($fresh->items->contains('stock_deducted', true));
        $this->assertSame([3, 10, $order->id], $this->inTenant($this->tenant, fn () => StockMovement::query()->where('reason', 'cancellation')->get()
            ->map(fn (StockMovement $m) => [$m->quantity_change, $m->balance_after, $m->order_id])->sole()));
        Event::assertDispatched(OrderCancelled::class);

        $this->assertThrows(
            fn () => $this->inTenant($this->tenant, fn () => app(ChangeOrderStatus::class)->handle($fresh, OrderStatus::Cancelled)),
            ValidationException::class,
        );
        $this->assertSame(10, $this->stockOf($this->tenant, $this->serum));
    }

    public function test_payments_are_recorded_until_the_order_is_paid(): void
    {
        Event::fake([OrderPaid::class]);
        $this->actingAs($this->owner);
        $order = $this->placeOrder($this->tenant, [[$this->serum, 2]]);
        $url = $this->appUrl("/orders/{$order->id}/payments");

        $this->post($url, ['amount' => '300', 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Partial, $this->inTenant($this->tenant, fn () => $order->fresh()->payment_status));
        Event::assertNotDispatched(OrderPaid::class);

        $this->post($url, ['amount' => '700', 'method' => 'upi'])->assertSessionHasErrors(['amount' => 'The amount cannot be more than the balance due (600.00).']);
        $this->post($url, ['amount' => '0', 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->post($url, ['amount' => '10', 'method' => 'bitcoin'])->assertSessionHasErrors('method');
        $this->post($url, ['amount' => '10', 'method' => 'cash', 'paid_at' => now()->addDays(2)->format('Y-m-d\TH:i')])->assertSessionHasErrors('paid_at');

        $this->post($url, ['amount' => '600', 'method' => 'upi', 'reference' => 'TXN-9'])->assertSessionHasNoErrors();
        $fresh = $this->inTenant($this->tenant, fn () => $order->fresh());
        $this->assertSame([PaymentStatus::Paid, '900.00', '0.00'], [$fresh->payment_status, (string) $fresh->amount_paid, $fresh->balance()]);
        Event::assertDispatched(OrderPaid::class, 1);

        $this->post($url, ['amount' => '1', 'method' => 'cash'])->assertSessionHasErrors(['amount' => 'This order is already fully paid.']);

        $activity = $this->inTenant($this->tenant, fn () => Activity::query()->where('type', 'payment_recorded')->latest('id')->first());
        $this->assertSame('UPI', $activity->metadata['method_label']);
    }

    public function test_a_payment_recorded_by_mistake_can_be_removed(): void
    {
        $this->actingAs($this->owner);
        $order = $this->placeOrder($this->tenant, [[$this->shampoo, 2]]);
        $payment = $this->inTenant($this->tenant, fn () => app(RecordOrderPayment::class)->handle($order, ['amount' => '399', 'method' => 'cash']));

        $this->delete($this->appUrl("/orders/{$order->id}/payments/{$payment->id}"))->assertSessionHasNoErrors();

        $fresh = $this->inTenant($this->tenant, fn () => $order->fresh());
        $this->assertSame([PaymentStatus::Unpaid, '0.00'], [$fresh->payment_status, (string) $fresh->amount_paid]);
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => OrderPayment::query()->count()));
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => Activity::query()->where('type', 'payment_removed')->count()));

        // A payment id from another order of the same business is refused.
        $other = $this->placeOrder($this->tenant, [[$this->shampoo, 1]]);
        $otherPayment = $this->inTenant($this->tenant, fn () => app(RecordOrderPayment::class)->handle($other, ['amount' => '10', 'method' => 'cash']));
        $this->delete($this->appUrl("/orders/{$order->id}/payments/{$otherPayment->id}"))->assertNotFound();
    }

    public function test_cancelled_orders_take_no_payments(): void
    {
        $order = $this->placeOrder($this->tenant, [[$this->shampoo, 1]]);
        $this->inTenant($this->tenant, fn () => app(ChangeOrderStatus::class)->handle($order, OrderStatus::Cancelled));

        $this->actingAs($this->owner)->post($this->appUrl("/orders/{$order->id}/payments"), ['amount' => '10', 'method' => 'cash'])->assertSessionHasErrors('amount');
    }

    public function test_order_pages_render(): void
    {
        $this->actingAs($this->owner);
        $customer = $this->makeCustomer($this->tenant, ['name' => 'Asha', 'address' => 'Baner, Pune']);
        $order = $this->placeOrder($this->tenant, [[$this->serum, 1]], ['customer_id' => $customer->id]);

        $this->get($this->appUrl('/orders'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/orders/Index')
            ->where('orders.data.0.reference', '#1001')
            ->where('orders.data.0.item_summary', '1 × Hair serum')
            ->where('counts.open', 1));
        $this->get($this->appUrl('/orders?status=completed'))->assertInertia(fn (Assert $page) => $page->where('orders.meta.total', 0));
        $this->get($this->appUrl('/orders?search=1001'))->assertInertia(fn (Assert $page) => $page->where('orders.meta.total', 1));
        $this->get($this->appUrl('/orders?search=Asha'))->assertInertia(fn (Assert $page) => $page->where('orders.meta.total', 1));

        $this->get($this->appUrl("/orders/create?customer={$customer->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/orders/Create')
            ->where('customer.address', 'Baner, Pune')
            ->where('products', fn ($products) => collect($products)->firstWhere('name', 'Hair serum')['available'] === 9));

        $this->get($this->appUrl("/orders/{$order->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/orders/Show')
            ->where('order.total', '450.00')
            ->where('transitions', fn ($transitions) => collect($transitions)->pluck('value')->all() === ['ready', 'completed', 'cancelled'])
            ->has('activities', 1));

        $this->put($this->appUrl("/orders/{$order->id}"), ['notes' => 'Call before delivery'])->assertSessionHasNoErrors();
        $this->assertSame('Call before delivery', $this->inTenant($this->tenant, fn () => $order->fresh()->notes));

        $this->get($this->appUrl("/customers/{$customer->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('orders.0.reference', '#1001')
            ->where('activities', fn ($activities) => collect($activities)->contains('type', 'order_placed')));
    }

    public function test_order_permissions(): void
    {
        $order = $this->placeOrder($this->tenant, [[$this->shampoo, 1]]);

        $receptionist = User::factory()->create();
        $this->addMember($this->tenant, $receptionist, 'receptionist');
        $this->actingAs($receptionist);
        $this->get($this->appUrl('/orders/create'))->assertOk();
        $this->post($this->appUrl('/orders'), $this->orderPayload())->assertSessionHasNoErrors();
        $this->patch($this->appUrl("/orders/{$order->id}/status"), ['status' => 'completed'])->assertForbidden();
        $this->post($this->appUrl("/orders/{$order->id}/payments"), ['amount' => '10', 'method' => 'cash'])->assertForbidden();

        $accountant = User::factory()->create();
        $this->addMember($this->tenant, $accountant, 'accountant');
        $this->actingAs($accountant);
        $this->get($this->appUrl("/orders/{$order->id}"))->assertOk();
        $this->get($this->appUrl('/orders/create'))->assertForbidden();
        $this->put($this->appUrl("/orders/{$order->id}"), ['notes' => 'x'])->assertForbidden();

        $staff = User::factory()->create();
        $this->addMember($this->tenant, $staff, 'staff');
        $this->actingAs($staff)->get($this->appUrl('/orders'))->assertForbidden();

        $sales = User::factory()->create();
        $this->addMember($this->tenant, $sales, 'sales_executive');
        $this->actingAs($sales)->get($this->appUrl("/customers/{$order->customer_id}"))->assertInertia(fn (Assert $page) => $page->where('orders', null));
    }
}
