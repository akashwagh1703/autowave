<?php

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Actions\StockLedger;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Domain\Commerce\Models\StockMovement;
use App\Domain\Media\Models\Media;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->owner = $this->ownerOf($this->tenant);
    }

    private function productPayload(array $overrides = []): array
    {
        return [
            'name' => 'Argan hair serum',
            'sku' => 'SER-100',
            'price' => '450',
            'compare_at_price' => '500',
            'description' => '100 ml bottle',
            'is_active' => true,
            'track_stock' => true,
            'opening_stock' => 12,
            'low_stock_threshold' => 3,
            ...$overrides,
        ];
    }

    public function test_a_product_is_created_with_opening_stock_in_the_ledger(): void
    {
        $this->actingAs($this->owner);

        $this->post($this->appUrl('/products'), $this->productPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->appUrl('/products'));

        $this->inTenant($this->tenant, function () {
            $product = Product::query()->sole();
            $this->assertSame('450.00', (string) $product->price);
            $this->assertSame('500.00', (string) $product->compare_at_price);
            $this->assertSame(12, $product->stock_quantity);
            $this->assertSame(3, $product->low_stock_threshold);
            $this->assertSame($this->owner->id, $product->created_by_user_id);

            $movement = StockMovement::query()->sole();
            $this->assertSame(['opening', 12, 12], [$movement->reason, $movement->quantity_change, $movement->balance_after]);
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created']);
    }

    public function test_product_rules_are_enforced(): void
    {
        $this->actingAs($this->owner);
        $this->makeProduct($this->tenant, ['name' => 'Shampoo', 'sku' => 'SH-1']);

        $this->post($this->appUrl('/products'), $this->productPayload(['name' => 'shampoo']))->assertSessionHasErrors('name');
        $this->post($this->appUrl('/products'), $this->productPayload(['sku' => 'sh-1']))->assertSessionHasErrors('sku');
        $this->post($this->appUrl('/products'), $this->productPayload(['sku' => 'bad<sku>']))->assertSessionHasErrors('sku');
        $this->post($this->appUrl('/products'), $this->productPayload(['compare_at_price' => '400']))->assertSessionHasErrors('compare_at_price');
        $this->post($this->appUrl('/products'), $this->productPayload(['price' => '-1']))->assertSessionHasErrors('price');
        $this->post($this->appUrl('/products'), $this->productPayload(['opening_stock' => -5]))->assertSessionHasErrors('opening_stock');

        $this->assertSame(1, $this->inTenant($this->tenant, fn () => Product::query()->count()));
    }

    public function test_deleted_products_free_their_name_and_sku(): void
    {
        $this->actingAs($this->owner);
        $old = $this->makeProduct($this->tenant, ['name' => 'Shampoo', 'sku' => 'SH-1']);

        $this->delete($this->appUrl("/products/{$old->id}"))->assertRedirect($this->appUrl('/products'));
        $this->assertSoftDeleted('products', ['id' => $old->id]);

        $this->post($this->appUrl('/products'), $this->productPayload(['name' => 'Shampoo', 'sku' => 'SH-1']))->assertSessionHasNoErrors();
    }

    public function test_updating_a_product_never_touches_stock(): void
    {
        $this->actingAs($this->owner);
        $product = $this->makeProduct($this->tenant, ['name' => 'Serum'], stock: 8);

        $this->put($this->appUrl("/products/{$product->id}"), $this->productPayload(['name' => 'Serum XL', 'opening_stock' => 100, 'track_stock' => true]))
            ->assertSessionHasNoErrors();

        $fresh = $this->inTenant($this->tenant, fn () => $product->fresh());
        $this->assertSame('Serum XL', $fresh->name);
        $this->assertSame(8, $fresh->stock_quantity);
    }

    public function test_stock_adjustments_add_remove_and_set_through_the_ledger(): void
    {
        $this->actingAs($this->owner);
        $product = $this->makeProduct($this->tenant, stock: 10);
        $url = $this->appUrl("/products/{$product->id}/stock");

        $this->post($url, ['mode' => 'add', 'quantity' => 5, 'reason' => 'restock', 'note' => 'Supplier delivery'])->assertSessionHasNoErrors();
        $this->post($url, ['mode' => 'remove', 'quantity' => 2, 'reason' => 'damaged'])->assertSessionHasNoErrors();
        $this->post($url, ['mode' => 'set', 'quantity' => 20, 'reason' => 'adjustment'])->assertSessionHasNoErrors();
        $this->post($url, ['mode' => 'set', 'quantity' => 20, 'reason' => 'adjustment'])->assertSessionHas('success', 'Stock is unchanged.');

        $this->assertSame(20, $this->stockOf($this->tenant, $product));
        $movements = $this->inTenant($this->tenant, fn () => StockMovement::query()->orderBy('id')->get(['reason', 'quantity_change', 'balance_after', 'note', 'created_by_user_id']));
        $this->assertSame(
            [['opening', 10, 10], ['restock', 5, 15], ['damaged', -2, 13], ['adjustment', 7, 20]],
            $movements->map(fn (StockMovement $m) => [$m->reason, $m->quantity_change, $m->balance_after])->all(),
        );
        $this->assertSame('Supplier delivery', $movements[1]->note);
        $this->assertSame($this->owner->id, $movements[1]->created_by_user_id);
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'product.stock_adjusted')->count());
    }

    public function test_stock_never_goes_below_zero(): void
    {
        $this->actingAs($this->owner);
        $product = $this->makeProduct($this->tenant, ['name' => 'Serum'], stock: 3);
        $untracked = $this->makeProduct($this->tenant);

        $this->post($this->appUrl("/products/{$product->id}/stock"), ['mode' => 'remove', 'quantity' => 4, 'reason' => 'damaged'])
            ->assertSessionHasErrors(['quantity' => 'Only 3 of Serum left in stock.']);
        // System reasons cannot be picked by hand, and untracked products have no stock to adjust.
        $this->post($this->appUrl("/products/{$product->id}/stock"), ['mode' => 'add', 'quantity' => 4, 'reason' => 'sale'])->assertSessionHasErrors('reason');
        $this->post($this->appUrl("/products/{$untracked->id}/stock"), ['mode' => 'add', 'quantity' => 4, 'reason' => 'restock'])->assertSessionHasErrors('quantity');

        $this->assertSame(3, $this->stockOf($this->tenant, $product));

        // The database check backs the rule up.
        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $product->id)->update(['stock_quantity' => -1]);
    }

    public function test_the_ledger_refuses_a_move_that_would_oversell(): void
    {
        $product = $this->makeProduct($this->tenant, ['name' => 'Serum'], stock: 1);

        $this->inTenant($this->tenant, fn () => app(StockLedger::class)->move($product, -1, 'sale'));

        try {
            $this->inTenant($this->tenant, fn () => app(StockLedger::class)->move($product, -1, 'sale', field: 'items'));
            $this->fail('An out-of-stock sale was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['items' => ['Serum is out of stock.']], $exception->errors());
        }

        $this->assertSame(0, $this->stockOf($this->tenant, $product));
    }

    public function test_the_list_filters_by_category_status_and_stock(): void
    {
        $this->actingAs($this->owner);
        $hair = $this->inTenant($this->tenant, fn () => ProductCategory::query()->create(['name' => 'Hair care', 'sort_order' => 10]));
        $this->makeProduct($this->tenant, ['name' => 'Serum', 'product_category_id' => $hair->id], stock: 2);
        $this->makeProduct($this->tenant, ['name' => 'Shampoo', 'product_category_id' => $hair->id], stock: 0);
        $this->makeProduct($this->tenant, ['name' => 'Gift card']);
        $this->makeProduct($this->tenant, ['name' => 'Old stock', 'is_active' => false], stock: 50);

        $names = fn (string $query) => collect($this->get($this->appUrl('/products'.$query))->assertOk()->viewData('page')['props']['products']['data'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Gift card', 'Old stock', 'Serum', 'Shampoo'], $names(''));
        $this->assertSame(['Serum', 'Shampoo'], $names("?category={$hair->id}"));
        $this->assertSame(['Serum', 'Shampoo'], $names('?stock=low'));
        $this->assertSame(['Shampoo'], $names('?stock=out'));
        $this->assertSame(['Old stock'], $names('?status=inactive'));
        $this->assertSame(['Serum'], $names('?search=ser'));

        $this->get($this->appUrl('/products'))->assertInertia(fn (Assert $page) => $page
            ->where('counts.all', 4)
            ->where('counts.low', 2)
            ->where('counts.out', 1));
    }

    public function test_bulk_actions_follow_permissions(): void
    {
        $a = $this->makeProduct($this->tenant);
        $b = $this->makeProduct($this->tenant);
        $accountant = User::factory()->create();
        $this->addMember($this->tenant, $accountant, 'accountant');

        $this->actingAs($accountant)->post($this->appUrl('/products/bulk'), ['action' => 'deactivate', 'ids' => [$a->id]])->assertForbidden();

        $this->actingAs($this->owner)->post($this->appUrl('/products/bulk'), ['action' => 'deactivate', 'ids' => [$a->id, $b->id]])->assertRedirect();
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Product::query()->active()->count()));

        $this->post($this->appUrl('/products/bulk'), ['action' => 'delete', 'ids' => [$a->id]])->assertRedirect();
        $this->assertSoftDeleted('products', ['id' => $a->id]);
    }

    public function test_images_are_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');
        $this->actingAs($this->owner);
        $product = $this->makeProduct($this->tenant);

        $this->post($this->appUrl("/products/{$product->id}/image"), ['image' => UploadedFile::fake()->image('one.jpg', 800, 800)])->assertSessionHasNoErrors();
        $first = $this->inTenant($this->tenant, fn () => $product->fresh()->image_media_id);
        $this->assertNotNull($first);

        $this->post($this->appUrl("/products/{$product->id}/image"), ['image' => UploadedFile::fake()->image('two.jpg', 800, 800)])->assertSessionHasNoErrors();
        $second = $this->inTenant($this->tenant, fn () => $product->fresh()->image_media_id);
        $this->assertNotSame($first, $second);
        $this->assertNull($this->inTenant($this->tenant, fn () => Media::query()->find($first)));

        $this->post($this->appUrl("/products/{$product->id}/image"), ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');

        $this->delete($this->appUrl("/products/{$product->id}/image"))->assertSessionHasNoErrors();
        $this->assertNull($this->inTenant($this->tenant, fn () => $product->fresh()->image_media_id));
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Media::query()->count()));
    }

    public function test_categories_are_managed_and_deleting_one_keeps_its_products(): void
    {
        $this->actingAs($this->owner);

        $this->post($this->appUrl('/product-categories'), ['name' => 'Nails'])->assertSessionHasNoErrors();
        $this->post($this->appUrl('/product-categories'), ['name' => 'nails'])->assertSessionHasErrors('name');
        $category = $this->inTenant($this->tenant, fn () => ProductCategory::query()->where('name', 'Nails')->sole());
        $product = $this->makeProduct($this->tenant, ['product_category_id' => $category->id]);

        $this->put($this->appUrl("/product-categories/{$category->id}"), ['name' => 'Nail care'])->assertSessionHasNoErrors();
        $this->delete($this->appUrl("/product-categories/{$category->id}"))->assertSessionHasNoErrors();

        $this->assertNull($this->inTenant($this->tenant, fn () => $product->fresh()->product_category_id));
    }

    public function test_product_pages_need_the_commerce_engine_and_permissions(): void
    {
        $receptionist = User::factory()->create();
        $this->addMember($this->tenant, $receptionist, 'receptionist');
        $product = $this->makeProduct($this->tenant);

        $this->actingAs($receptionist)->get($this->appUrl('/products'))->assertForbidden();
        $this->actingAs($receptionist)->post($this->appUrl("/products/{$product->id}/stock"), ['mode' => 'add', 'quantity' => 1, 'reason' => 'restock'])->assertForbidden();

        $accountant = User::factory()->create();
        $this->addMember($this->tenant, $accountant, 'accountant');
        $this->actingAs($accountant)->get($this->appUrl('/products'))->assertOk();
        $this->actingAs($accountant)->get($this->appUrl("/products/{$product->id}/edit"))->assertForbidden();

        $turf = $this->createTenant('Green Turf', 'turf');
        $this->actingAs($this->ownerOf($turf))->get($this->appUrl('/products'))->assertNotFound();
        $this->actingAs($this->ownerOf($turf))->get($this->appUrl('/orders'))->assertNotFound();
    }
}
