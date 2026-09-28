<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Actions\ChangeOrderStatus;
use App\Domain\Commerce\Actions\SaveCoupon;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Coupon codes on orders (offers module, ADR-020) and the Local Commerce polish. */
class CouponTest extends TestCase
{
    use CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const STORE = 'abc-store.autowave.test';

    private Tenant $tenant;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant('ABC Store', 'local_store');
        $this->rice = $this->makeProduct($this->tenant, ['name' => 'Basmati rice', 'price' => '500.00'], stock: 50);
    }

    /** @param  array<string, mixed>  $data */
    private function coupon(array $data = [], ?Tenant $tenant = null): Coupon
    {
        return $this->inTenant($tenant ?? $this->tenant, fn () => app(SaveCoupon::class)->handle([
            'code' => 'SAVE10', 'type' => 'percent', 'value' => 10, 'online' => true, 'is_active' => true, ...$data,
        ]));
    }

    public function test_the_owner_manages_coupons(): void
    {
        $this->actingAs($this->ownerOf($this->tenant));
        $valid = ['code' => ' diwali-15 ', 'description' => 'Festive offer', 'type' => 'percent', 'value' => 15, 'max_discount' => 200, 'online' => true, 'is_active' => true];

        $this->post($this->appUrl('/offers'), $valid)->assertSessionHasNoErrors();
        $this->post($this->appUrl('/offers'), [...$valid, 'code' => 'DIWALI-15'])->assertSessionHasErrors('code');
        $this->post($this->appUrl('/offers'), [...$valid, 'code' => 'BIG', 'value' => 150])->assertSessionHasErrors('value');
        $this->post($this->appUrl('/offers'), [...$valid, 'code' => 'BAD!CODE'])->assertSessionHasErrors('code');
        $this->post($this->appUrl('/offers'), [...$valid, 'code' => 'LATER', 'starts_at' => '2026-11-10T10:00', 'ends_at' => '2026-11-01T10:00'])->assertSessionHasErrors('ends_at');

        $coupon = $this->inTenant($this->tenant, fn () => Coupon::query()->sole());
        $this->assertSame(['DIWALI-15', '15.00', '200.00'], [$coupon->code, (string) $coupon->value, (string) $coupon->max_discount]);

        $this->put($this->appUrl("/offers/{$coupon->id}"), [...$valid, 'code' => 'DIWALI-15', 'type' => 'fixed', 'value' => 100])->assertSessionHasNoErrors();
        $this->assertNull($this->inTenant($this->tenant, fn () => $coupon->fresh()->max_discount), 'Fixed coupons have no cap.');

        $this->get($this->appUrl('/offers'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('business/offers/Index')->where('coupons.0.code', 'DIWALI-15'));
        $this->delete($this->appUrl("/offers/{$coupon->id}"))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
    }

    public function test_staff_orders_take_a_coupon_use_and_cancelling_gives_it_back(): void
    {
        $coupon = $this->coupon(['type' => 'percent', 'value' => 10, 'max_discount' => 80, 'usage_limit' => 1]);
        $customer = $this->makeCustomer($this->tenant);
        $this->actingAs($this->ownerOf($this->tenant));

        $this->postJson($this->appUrl('/orders/coupon'), ['code' => 'save10', 'items' => [['product_id' => $this->rice->id, 'quantity' => 2]]])
            ->assertOk()->assertExactJson(['code' => 'SAVE10', 'summary' => '10% off', 'discount' => '80.00']);
        $this->postJson($this->appUrl('/orders/coupon'), ['code' => 'NOPE', 'items' => [['product_id' => $this->rice->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->post($this->appUrl('/orders'), [
            'customer_id' => $customer->id, 'items' => [['product_id' => $this->rice->id, 'quantity' => 2]], 'fulfilment' => 'in_store', 'discount' => 20, 'coupon_code' => 'save10',
        ])->assertSessionHasNoErrors();

        $order = $this->inTenant($this->tenant, fn () => Order::query()->sole());
        $this->assertSame(['1000.00', '100.00', '900.00', 'SAVE10', $coupon->id], [(string) $order->subtotal, (string) $order->discount, (string) $order->total, $order->coupon_code, $order->coupon_id]);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => $coupon->fresh()->times_used));

        $this->post($this->appUrl('/orders'), ['customer_id' => $customer->id, 'items' => [['product_id' => $this->rice->id, 'quantity' => 1]], 'fulfilment' => 'in_store', 'coupon_code' => 'SAVE10'])
            ->assertSessionHasErrors(['coupon_code' => 'This coupon has been fully used.']);

        $this->inTenant($this->tenant, fn () => app(ChangeOrderStatus::class)->handle($order, OrderStatus::Cancelled));
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => $coupon->fresh()->times_used));
    }

    public function test_coupon_rules_are_enforced(): void
    {
        $this->coupon(['code' => 'BIGBASKET', 'type' => 'fixed', 'value' => 100, 'min_subtotal' => 1000]);
        $this->coupon(['code' => 'OLD', 'ends_at' => now()->subDay()]);
        $this->coupon(['code' => 'SOON', 'starts_at' => now()->addDay()]);
        $this->coupon(['code' => 'PAUSED', 'is_active' => false]);
        $this->coupon(['code' => 'HUGE', 'type' => 'fixed', 'value' => 5000]);
        $this->actingAs($this->ownerOf($this->tenant));
        $check = fn (string $code, int $quantity = 1) => $this->postJson($this->appUrl('/orders/coupon'), ['code' => $code, 'items' => [['product_id' => $this->rice->id, 'quantity' => $quantity]]]);

        $check('BIGBASKET')->assertJsonValidationErrors(['code' => 'This coupon needs an order of at least 1000.00.']);
        $check('BIGBASKET', 2)->assertOk()->assertJsonPath('discount', '100.00');
        $check('OLD')->assertJsonValidationErrors(['code' => 'This coupon has expired.']);
        $check('SOON')->assertJsonValidationErrors(['code' => 'This coupon is not active yet.']);
        $check('PAUSED')->assertJsonValidationErrors(['code' => 'This coupon code is not valid.']);
        $check('HUGE')->assertOk()->assertJsonPath('discount', '500.00');
    }

    public function test_website_orders_only_accept_online_coupons(): void
    {
        $this->coupon(['code' => 'WEB20', 'type' => 'fixed', 'value' => 20]);
        $this->coupon(['code' => 'COUNTER', 'online' => false]);
        $items = [['product_id' => $this->rice->id, 'quantity' => 1]];

        $this->postJson($this->siteUrl(self::STORE, '/cart/quote'), ['items' => $items, 'fulfilment' => 'pickup', 'coupon_code' => 'web20'])
            ->assertOk()
            ->assertJsonPath('discount', '20.00')
            ->assertJsonPath('coupon.code', 'WEB20')
            ->assertJsonPath('total', '480.00')
            ->assertJsonPath('coupon_error', null);
        $this->postJson($this->siteUrl(self::STORE, '/cart/quote'), ['items' => $items, 'fulfilment' => 'pickup', 'coupon_code' => 'COUNTER'])
            ->assertOk()
            ->assertJsonPath('coupon', null)
            ->assertJsonPath('discount', '0.00')
            ->assertJsonPath('coupon_error', 'This coupon code is not valid.');

        $order = fn (string $code) => $this->from($this->siteUrl(self::STORE))->post($this->siteUrl(self::STORE, '/orders'), [
            'name' => 'Meena Gupta', 'phone' => '95000 10001', 'fulfilment' => 'pickup', 'items' => $items, 'coupon_code' => $code,
        ]);

        $order('COUNTER')->assertSessionHasErrors('coupon_code');
        $order('WEB20')->assertSessionHasNoErrors();

        $placed = $this->inTenant($this->tenant, fn () => Order::query()->sole());
        $this->assertSame(['20.00', '480.00', 'website'], [(string) $placed->discount, (string) $placed->total, $placed->source]);
        $this->get($this->siteUrl(self::STORE))->assertInertia(fn (Assert $page) => $page->where('orderConfirmation.discount', '20.00')->where('shop.coupons', true));
    }

    public function test_coupons_need_the_offers_module(): void
    {
        $this->coupon();
        $this->disableModule($this->tenant, 'offers');
        $customer = $this->makeCustomer($this->tenant);
        $this->actingAs($this->ownerOf($this->tenant));

        $this->get($this->appUrl('/offers'))->assertNotFound();
        $this->postJson($this->appUrl('/orders/coupon'), ['code' => 'SAVE10', 'items' => [['product_id' => $this->rice->id, 'quantity' => 1]]])->assertNotFound();
        $this->post($this->appUrl('/orders'), ['customer_id' => $customer->id, 'items' => [['product_id' => $this->rice->id, 'quantity' => 1]], 'fulfilment' => 'in_store', 'coupon_code' => 'SAVE10'])
            ->assertSessionHasErrors(['coupon_code' => 'Coupon codes are not accepted.']);
        $this->get($this->siteUrl(self::STORE))->assertInertia(fn (Assert $page) => $page->where('shop.coupons', false));
    }

    public function test_tenant_a_cannot_use_or_change_tenant_b_coupons(): void
    {
        $other = $this->createTenant('Other Store', 'local_store');
        $theirs = $this->coupon(['code' => 'THEIRS'], $other);
        $this->actingAs($this->ownerOf($this->tenant));

        $this->postJson($this->appUrl('/orders/coupon'), ['code' => 'THEIRS', 'items' => [['product_id' => $this->rice->id, 'quantity' => 1]]])->assertJsonValidationErrors('code');
        $this->put($this->appUrl("/offers/{$theirs->id}"), ['code' => 'HIJACK', 'type' => 'fixed', 'value' => 1, 'online' => true, 'is_active' => true])->assertNotFound();
        $this->delete($this->appUrl("/offers/{$theirs->id}"))->assertNotFound();
        $this->get($this->appUrl('/offers'))->assertInertia(fn (Assert $page) => $page->where('coupons', []));

        // The same code may exist in both businesses.
        $this->coupon(['code' => 'THEIRS']);
        $this->assertSame('THEIRS', Coupon::withoutTenantScope()->findOrFail($theirs->id)->code);
        $this->assertSame(2, Coupon::withoutTenantScope()->where('code', 'THEIRS')->count());
    }

    public function test_local_commerce_dashboard_shows_repeat_customers_and_top_products(): void
    {
        $oil = $this->makeProduct($this->tenant, ['name' => 'Sunflower oil', 'price' => '150.00']);
        $meena = $this->makeCustomer($this->tenant);
        $this->placeOrder($this->tenant, [[$this->rice, 1], [$oil, 3]], ['customer_id' => $meena->id, 'completed' => true]);
        $this->placeOrder($this->tenant, [[$oil, 2]], ['customer_id' => $meena->id, 'completed' => true]);
        $this->placeOrder($this->tenant, [[$this->rice, 1]], ['completed' => true]);
        $this->actingAs($this->ownerOf($this->tenant));

        $this->assertSame('Local Commerce', config('catalog.business_types.local_store.name'));
        $this->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('metrics.repeat_customers.value', 1)
            ->where('metrics.top_products.type', 'list')
            ->where('metrics.top_products.items', [['label' => 'Sunflower oil', 'value' => 5], ['label' => 'Basmati rice', 'value' => 2]]));
    }
}
