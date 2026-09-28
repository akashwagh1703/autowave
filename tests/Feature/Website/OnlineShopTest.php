<?php

namespace Tests\Feature\Website;

use App\Domain\Activity\Models\Activity;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Models\WebsiteSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OnlineShopTest extends TestCase
{
    use CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const SALON = 'abc-salon.autowave.test';

    private Tenant $tenant;

    private Product $serum;

    private Product $comb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->serum = $this->makeProduct($this->tenant, ['name' => 'Hair serum', 'price' => '450.00', 'compare_at_price' => '500.00'], stock: 5);
        $this->comb = $this->makeProduct($this->tenant, ['name' => 'Wooden comb', 'price' => '120.00']);
    }

    private function items(array $overrides = []): array
    {
        return $overrides ?: [['product_id' => $this->serum->id, 'quantity' => 2], ['product_id' => $this->comb->id, 'quantity' => 1]];
    }

    private function quote(array $body)
    {
        return $this->postJson($this->siteUrl(self::SALON, '/cart/quote'), $body);
    }

    private function orderOnline(array $data = [], string $host = self::SALON)
    {
        return $this->from($this->siteUrl($host))->post($this->siteUrl($host, '/orders'), [
            'name' => 'Meera Joshi',
            'phone' => '99887 76655',
            'email' => 'meera@example.com',
            'fulfilment' => 'pickup',
            'notes' => 'Evening pickup',
            'items' => $this->items(),
            ...$data,
        ]);
    }

    public function test_the_products_section_shows_products_and_the_shop(): void
    {
        $this->makeProduct($this->tenant, ['name' => 'Hidden', 'is_active' => false]);
        $this->makeProduct($this->tenant, ['name' => 'Sold out'], stock: 0);

        $this->get($this->siteUrl(self::SALON))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop.fulfilment', ['pickup'])
                ->where('shop.max_quantity', 999)
                ->where('sections', function ($sections) {
                    $products = collect(collect($sections)->firstWhere('type', 'products')['data'])->flatMap(fn ($group) => $group['products'])->keyBy('name');

                    return $products->keys()->sort()->values()->all() === ['Hair serum', 'Sold out', 'Wooden comb']
                        && $products['Hair serum']['price'] === '450.00'
                        && $products['Hair serum']['compare_at_price'] === '500.00'
                        && $products['Hair serum']['max_quantity'] === 5
                        && $products['Sold out']['in_stock'] === false
                        // Exact stock stays private.
                        && ! array_key_exists('stock_quantity', $products['Hair serum']);
                }));
    }

    public function test_the_quote_uses_server_prices_and_reports_stock_problems(): void
    {
        $this->quote(['items' => [['product_id' => $this->serum->id, 'quantity' => 2, 'price' => '1'], ['product_id' => $this->comb->id, 'quantity' => 1]]])
            ->assertOk()
            ->assertJson(['subtotal' => '1020.00', 'delivery_fee' => '0.00', 'total' => '1020.00', 'issues' => [], 'can_checkout' => true])
            ->assertJsonPath('lines.0.unit_price', '450.00');

        $this->quote(['items' => [['product_id' => $this->serum->id, 'quantity' => 9]]])
            ->assertOk()
            ->assertJson(['can_checkout' => false, 'issues' => ['Only 5 of Hair serum left in stock.']])
            ->assertJsonPath('lines.0.issue', 'Only 5 of Hair serum left in stock.');

        $this->quote(['items' => []])->assertStatus(422);
        $this->quote(['items' => [['product_id' => $this->serum->id, 'quantity' => 1000]]])->assertStatus(422);
    }

    public function test_a_website_order_is_pending_takes_stock_and_creates_the_customer(): void
    {
        $this->orderOnline(['items' => [['product_id' => $this->serum->id, 'quantity' => 2, 'price' => '1.00'], ['product_id' => $this->comb->id, 'quantity' => 1]], 'discount' => '500', 'completed' => true, 'payment' => ['amount' => '1020', 'method' => 'cash']])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->siteUrl(self::SALON))
            ->assertSessionHas('order_confirmation', fn (array $confirmation) => $confirmation['number'] === '#1001'
                && $confirmation['status'] === 'pending'
                && $confirmation['total'] === '1020.00'
                && $confirmation['items'] === '2 × Hair serum, 1 × Wooden comb');

        $this->inTenant($this->tenant, function () {
            $order = Order::query()->with(['customer', 'payments'])->sole();
            $this->assertSame(OrderStatus::Pending, $order->status);
            $this->assertSame('website', $order->source);
            $this->assertSame('pickup', $order->fulfilment);
            // Discounts, instant completion and payments cannot come from the website.
            $this->assertSame(['1020.00', '0.00', '1020.00', '0.00'], [(string) $order->subtotal, (string) $order->discount, (string) $order->total, (string) $order->amount_paid]);
            $this->assertCount(0, $order->payments);
            $this->assertNull($order->created_by_user_id);
            $this->assertSame('Evening pickup', $order->notes);
            $this->assertSame(3, Product::query()->findOrFail($this->serum->id)->stock_quantity);

            $customer = $order->customer;
            $this->assertSame('+919988776655', $customer->phone_normalized);
            $this->assertSame('online_order', $customer->activities()->where('type', 'created')->sole()->metadata['via']);
            $this->assertSame('website', Activity::query()->where('type', 'order_placed')->sole()->metadata['source']);
        });

        // Shown once, on the page the visitor is sent back to.
        $this->get($this->siteUrl(self::SALON))->assertInertia(fn (Assert $page) => $page->where('orderConfirmation.number', '#1001'));
        $this->get($this->siteUrl(self::SALON))->assertInertia(fn (Assert $page) => $page->where('orderConfirmation', null));
    }

    public function test_auto_confirm_and_returning_customers(): void
    {
        $this->setOnlineOrdering($this->tenant, ['auto_confirm' => true]);
        $existing = $this->makeCustomer($this->tenant, ['name' => 'Meera', 'phone' => '+91 99887 76655']);

        $this->orderOnline()->assertSessionHasNoErrors();

        $order = $this->inTenant($this->tenant, fn () => Order::query()->sole());
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame($existing->id, $order->customer_id);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => Customer::query()->count()));
    }

    public function test_delivery_fees_free_delivery_and_the_minimum_order(): void
    {
        $this->setOnlineOrdering($this->tenant, ['delivery' => true, 'delivery_fee' => '50', 'free_delivery_over' => '1000', 'min_order' => '200']);

        $this->quote(['items' => [['product_id' => $this->comb->id, 'quantity' => 1]], 'fulfilment' => 'delivery'])
            ->assertJson(['subtotal' => '120.00', 'delivery_fee' => '50.00', 'total' => '170.00', 'min_order' => '200.00', 'can_checkout' => false, 'issues' => ['The minimum order is 200.00.']]);
        $this->quote(['items' => $this->items(), 'fulfilment' => 'delivery'])->assertJson(['delivery_fee' => '0.00', 'total' => '1020.00']);

        $this->orderOnline(['items' => [['product_id' => $this->comb->id, 'quantity' => 1]]])->assertSessionHasErrors(['items' => 'The minimum order is 200.00.']);
        $this->orderOnline(['fulfilment' => 'delivery'])->assertSessionHasErrors('delivery_address');
        $this->orderOnline(['items' => [['product_id' => $this->comb->id, 'quantity' => 2]], 'fulfilment' => 'delivery', 'delivery_address' => '12 MG Road'])->assertSessionHasNoErrors();

        $order = $this->inTenant($this->tenant, fn () => Order::query()->sole());
        $this->assertSame(['240.00', '50.00', '290.00', '12 MG Road'], [(string) $order->subtotal, (string) $order->delivery_fee, (string) $order->total, $order->delivery_address]);
    }

    public function test_website_order_rules_are_enforced(): void
    {
        // In store is for the team only, and delivery is off for this business.
        $this->orderOnline(['fulfilment' => 'in_store'])->assertSessionHasErrors(['fulfilment' => 'Choose pickup or delivery.']);
        $this->orderOnline(['fulfilment' => 'delivery', 'delivery_address' => 'Somewhere'])->assertSessionHasErrors('fulfilment');
        $this->orderOnline(['items' => [['product_id' => $this->serum->id, 'quantity' => 6]]])->assertSessionHasErrors(['items' => 'Only 5 of Hair serum left in stock.']);
        $this->orderOnline(['items' => []])->assertSessionHasErrors('items');
        $this->orderOnline(['name' => '', 'phone' => 'abc'])->assertSessionHasErrors(['name', 'phone']);

        $other = $this->createTenant('Other Salon');
        $theirs = $this->makeProduct($other, ['name' => 'Their product']);
        $this->orderOnline(['items' => [['product_id' => $theirs->id, 'quantity' => 1]]])->assertSessionHasErrors(['items' => 'One of the products is no longer available.']);

        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Order::query()->count()));
        $this->assertSame(5, $this->stockOf($this->tenant, $this->serum));
    }

    public function test_the_shop_closes_when_ordering_or_the_section_is_off(): void
    {
        $this->setOnlineOrdering($this->tenant, ['enabled' => false]);
        $this->quote(['items' => $this->items()])->assertNotFound();
        $this->orderOnline()->assertNotFound();
        $this->get($this->siteUrl(self::SALON))->assertInertia(fn (Assert $page) => $page
            ->where('shop', null)
            // The catalogue still shows, without a cart.
            ->where('sections', fn ($sections) => collect($sections)->contains('type', 'products')));

        $this->setOnlineOrdering($this->tenant, ['enabled' => true]);
        $this->inTenant($this->tenant, fn () => WebsiteSection::query()->where('type', 'products')->update(['enabled' => false]));
        $this->orderOnline()->assertNotFound();
        $this->get($this->siteUrl(self::SALON))->assertInertia(fn (Assert $page) => $page->where('shop', null));

        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Order::query()->count()));
    }

    public function test_businesses_without_commerce_have_no_shop(): void
    {
        $turf = $this->createTenant('Green Turf', 'turf');

        $this->postJson($this->siteUrl('green-turf.autowave.test', '/cart/quote'), ['items' => $this->items()])->assertNotFound();
        $this->get($this->siteUrl('green-turf.autowave.test'))->assertInertia(fn (Assert $page) => $page
            ->where('shop', null)
            ->where('sections', fn ($sections) => ! collect($sections)->contains('type', 'products')));
        $this->assertSame(0, $this->inTenant($turf, fn () => Order::query()->count()));
    }

    public function test_honeypot_and_rate_limit(): void
    {
        $this->orderOnline(['company_website' => 'x'])->assertRedirect();
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Order::query()->count()));

        // The honeypot request above counts too.
        config(['commerce.online_per_hour' => 3]);
        $this->orderOnline(['name' => ''])->assertSessionHasErrors('name');
        $this->orderOnline(['name' => ''])->assertSessionHasErrors('name');
        $this->orderOnline()->assertStatus(429);
    }

    public function test_the_team_is_told_about_new_website_orders(): void
    {
        $this->orderOnline()->assertSessionHasNoErrors();

        $message = $this->inTenant($this->tenant, fn () => OutboundMessage::query()->where('channel', 'email')->sole());
        $this->assertSame($this->ownerOf($this->tenant)->email, $message->recipient);
        $this->assertSame('New website order #1001', $message->subject);

        // Orders taken by the team do not trigger the alert.
        $this->placeOrder($this->tenant, [[$this->comb, 1]]);
        $this->assertSame(1, $this->inTenant($this->tenant, fn () => OutboundMessage::query()->where('channel', 'email')->count()));
    }
}
