<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Actions\RecordOrderPayment;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Domain\Tenant\Exceptions\CrossTenantWrite;
use App\Domain\Tenant\Exceptions\MissingTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Mandatory cross-tenant tests (master prompt §86) for products, stock and orders.
 */
class CommerceIsolationTest extends TestCase
{
    use CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_tenant_a_cannot_touch_tenant_b_products(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirs = $this->makeProduct($b, ['name' => 'Their serum', 'price' => '300.00'], stock: 4);
        $theirCategory = $this->inTenant($b, fn () => ProductCategory::query()->create(['name' => 'Their category', 'sort_order' => 10]));
        $this->actingAs($this->ownerOf($a));

        $this->get($this->appUrl("/products/{$theirs->id}/edit"))->assertNotFound();
        $this->put($this->appUrl("/products/{$theirs->id}"), ['name' => 'Hijacked', 'price' => 1, 'is_active' => true, 'track_stock' => true])->assertNotFound();
        $this->post($this->appUrl("/products/{$theirs->id}/stock"), ['mode' => 'set', 'quantity' => 0, 'reason' => 'adjustment'])->assertNotFound();
        $this->delete($this->appUrl("/products/{$theirs->id}/image"))->assertNotFound();
        $this->delete($this->appUrl("/products/{$theirs->id}"))->assertNotFound();
        $this->put($this->appUrl("/product-categories/{$theirCategory->id}"), ['name' => 'Hijacked'])->assertNotFound();
        $this->delete($this->appUrl("/product-categories/{$theirCategory->id}"))->assertNotFound();
        $this->post($this->appUrl('/products/bulk'), ['action' => 'delete', 'ids' => [$theirs->id]])->assertRedirect();
        $this->post($this->appUrl('/products'), ['name' => 'Mine', 'price' => 10, 'is_active' => true, 'track_stock' => false, 'product_category_id' => $theirCategory->id])
            ->assertSessionHasErrors('product_category_id');

        $this->get($this->appUrl('/products'))->assertInertia(fn (Assert $page) => $page->where('products.meta.total', 0)->where('counts.all', 0));

        $fresh = Product::withoutTenantScope()->findOrFail($theirs->id);
        $this->assertSame(['Their serum', '300.00', 4, null], [$fresh->name, (string) $fresh->price, $fresh->stock_quantity, $fresh->deleted_at]);
        $this->assertDatabaseHas('product_categories', ['id' => $theirCategory->id, 'name' => 'Their category']);
    }

    public function test_tenant_a_cannot_touch_tenant_b_orders_or_payments(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirProduct = $this->makeProduct($b, stock: 5);
        $theirs = $this->placeOrder($b, [[$theirProduct, 2]], ['notes' => 'Secret note']);
        $theirPayment = $this->inTenant($b, fn () => app(RecordOrderPayment::class)->handle($theirs, ['amount' => '100', 'method' => 'cash']));
        $mine = $this->placeOrder($a, [[$this->makeProduct($a), 1]]);
        $this->actingAs($this->ownerOf($a));

        $this->get($this->appUrl("/orders/{$theirs->id}"))->assertNotFound();
        $this->put($this->appUrl("/orders/{$theirs->id}"), ['notes' => 'Hijacked'])->assertNotFound();
        $this->patch($this->appUrl("/orders/{$theirs->id}/status"), ['status' => 'cancelled'])->assertNotFound();
        $this->post($this->appUrl("/orders/{$theirs->id}/payments"), ['amount' => '10', 'method' => 'cash'])->assertNotFound();
        $this->delete($this->appUrl("/orders/{$theirs->id}/payments/{$theirPayment->id}"))->assertNotFound();
        $this->delete($this->appUrl("/orders/{$mine->id}/payments/{$theirPayment->id}"))->assertNotFound();

        $this->get($this->appUrl('/orders?status=all'))->assertInertia(fn (Assert $page) => $page
            ->where('orders.meta.total', 1)
            ->where('orders.data.0.id', $mine->id));
        $found = $this->getJson($this->appUrl('/orders/customers?search=Customer'))->assertOk()->json('data');
        $this->assertSame([$mine->customer_id], array_column($found, 'id'));

        $fresh = Order::withoutTenantScope()->findOrFail($theirs->id);
        $this->assertSame([OrderStatus::Confirmed, 'Secret note', '100.00'], [$fresh->status, $fresh->notes, (string) $fresh->amount_paid]);
        $this->assertSame(3, Product::withoutTenantScope()->findOrFail($theirProduct->id)->stock_quantity);
    }

    public function test_tenant_a_cannot_order_tenant_b_products_or_customers(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $mine = $this->makeProduct($a);
        $theirProduct = $this->makeProduct($b, stock: 5);
        $theirCustomer = $this->makeCustomer($b);
        $this->actingAs($this->ownerOf($a));

        $this->post($this->appUrl('/orders'), ['customer_id' => $theirCustomer->id, 'items' => [['product_id' => $mine->id, 'quantity' => 1]], 'fulfilment' => 'in_store'])
            ->assertSessionHasErrors('customer_id');
        $this->post($this->appUrl('/orders'), ['customer' => ['name' => 'Walk-in'], 'items' => [['product_id' => $theirProduct->id, 'quantity' => 1]], 'fulfilment' => 'in_store'])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Order::withoutTenantScope()->count());
        $this->assertSame(5, Product::withoutTenantScope()->findOrFail($theirProduct->id)->stock_quantity);
    }

    public function test_composite_foreign_keys_reject_cross_tenant_references(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $order = $this->placeOrder($a, [[$this->makeProduct($a), 1]]);
        $theirCustomer = $this->makeCustomer($b);
        $theirProduct = $this->makeProduct($b);
        $theirCategory = $this->inTenant($b, fn () => ProductCategory::query()->create(['name' => 'Theirs', 'sort_order' => 10]));
        $myProduct = $this->makeProduct($a);

        $attempts = [
            fn () => DB::table('orders')->where('id', $order->id)->update(['customer_id' => $theirCustomer->id]),
            fn () => DB::table('order_items')->where('order_id', $order->id)->update(['product_id' => $theirProduct->id]),
            fn () => DB::table('products')->where('id', $myProduct->id)->update(['product_category_id' => $theirCategory->id]),
            fn () => DB::table('stock_movements')->insert(['tenant_id' => $a->id, 'product_id' => $theirProduct->id, 'quantity_change' => 1, 'balance_after' => 1, 'reason' => 'restock', 'created_at' => now()]),
        ];

        foreach ($attempts as $index => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("Cross-tenant write #{$index} was accepted.");
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->errorInfo[0]);
            }
        }
    }

    public function test_commerce_models_fail_closed_without_a_tenant(): void
    {
        $tenant = $this->createTenant();
        $this->placeOrder($tenant, [[$this->makeProduct($tenant), 1]]);

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Order::query()->count());

        $this->expectException(MissingTenantContext::class);
        Product::query()->create(['name' => 'Orphan', 'price' => 1]);
    }

    public function test_writing_a_product_into_another_tenant_is_refused(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        $this->expectException(CrossTenantWrite::class);
        $this->inTenant($a, fn () => Product::query()->create(['tenant_id' => $b->id, 'name' => 'Sneaky', 'price' => 1]));
    }
}
