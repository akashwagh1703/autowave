<?php

namespace Tests\Concerns;

use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\SaveProduct;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

/** Commerce fixtures. Use with CreatesCrmRecords and CreatesTenants. */
trait CreatesCommerceRecords
{
    /**
     * A product priced 250.00; pass `stock` to track stock with that opening quantity.
     *
     * @param  array<string, mixed>  $data
     */
    protected function makeProduct(Tenant $tenant, array $data = [], ?int $stock = null): Product
    {
        static $sequence = 0;
        $sequence++;

        return $this->inTenant($tenant, fn () => app(SaveProduct::class)->handle([
            'name' => 'Product '.$sequence,
            'price' => '250.00',
            'is_active' => true,
            'track_stock' => $stock !== null,
            'opening_stock' => $stock,
            ...$data,
        ]));
    }

    /**
     * A staff order (in store unless `fulfilment` is given). Items are [product, quantity] pairs;
     * without a customer a new one is created.
     *
     * @param  list<array{0: Product, 1: int}>  $items
     * @param  array<string, mixed>  $data
     */
    protected function placeOrder(Tenant $tenant, array $items, array $data = [], ?User $actor = null): Order
    {
        if (! isset($data['customer']) && ! isset($data['customer_id'])) {
            $data['customer_id'] = $this->makeCustomer($tenant)->id;
        }

        return $this->inTenant($tenant, fn () => app(PlaceOrder::class)->handle([
            'items' => array_map(fn (array $item) => ['product_id' => $item[0]->id, 'quantity' => $item[1]], $items),
            'fulfilment' => 'in_store',
            ...$data,
        ], $actor));
    }

    /** @param  array<string, mixed>  $values */
    protected function setOnlineOrdering(Tenant $tenant, array $values): void
    {
        $this->inTenant($tenant, function () use ($values) {
            $settings = app(CommerceSettings::class);
            $settings->updateOnline([...$settings->online(), ...$values]);
        });
    }

    protected function stockOf(Tenant $tenant, Product $product): int
    {
        return $this->inTenant($tenant, fn () => Product::withTrashed()->findOrFail($product->id)->stock_quantity);
    }
}
