<?php

namespace Database\Seeders;

use App\Domain\Commerce\Actions\ChangeOrderStatus;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\SaveProduct;
use App\Domain\Commerce\Actions\SaveProductCategory;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo products, stock and orders for ABC Salon, created through the domain actions.
 * Runs once (skips if the salon already has products).
 */
class DemoCommerceSeeder extends Seeder
{
    public function run(TenantContext $context, SaveProductCategory $saveCategory, SaveProduct $saveProduct, PlaceOrder $placeOrder, ChangeOrderStatus $changeStatus): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCommerceSeeder must not run in production.');
        }

        $salon = Tenant::query()->where('slug', 'abc-salon')->first();

        if (! $salon) {
            return;
        }

        $context->run($salon, function () use ($saveCategory, $saveProduct, $placeOrder, $changeStatus) {
            if (Product::withTrashed()->exists()) {
                return;
            }

            $owner = User::query()->where('email', 'owner@abc-salon.test')->first();
            $categories = collect(['Hair care', 'Skin care', 'Nails'])->mapWithKeys(fn (string $name) => [$name => $saveCategory->handle($name)->id]);

            $products = collect([
                ['name' => 'Argan oil hair serum', 'category' => 'Hair care', 'sku' => 'HC-SERUM-100', 'price' => 650, 'compare_at_price' => 750, 'stock' => 18, 'description' => 'Lightweight serum for frizz-free, glossy hair. 100 ml.'],
                ['name' => 'Keratin shampoo', 'category' => 'Hair care', 'sku' => 'HC-SHAM-250', 'price' => 480, 'stock' => 4, 'description' => 'Sulphate-free shampoo for treated hair. 250 ml.'],
                ['name' => 'Deep conditioning mask', 'category' => 'Hair care', 'sku' => 'HC-MASK-200', 'price' => 720, 'stock' => 10],
                ['name' => 'Vitamin C face serum', 'category' => 'Skin care', 'sku' => 'SC-VITC-30', 'price' => 899, 'stock' => 12, 'description' => 'Brightening serum for daily use. 30 ml.'],
                ['name' => 'SPF 50 sunscreen', 'category' => 'Skin care', 'sku' => 'SC-SPF-50', 'price' => 549, 'stock' => 0],
                ['name' => 'Nail care kit', 'category' => 'Nails', 'sku' => 'NL-KIT', 'price' => 399, 'stock' => 7],
                ['name' => 'Gift voucher ₹1000', 'category' => null, 'sku' => 'GIFT-1000', 'price' => 1000, 'stock' => null, 'description' => 'Redeemable on any service.'],
            ])->mapWithKeys(fn (array $item) => [$item['name'] => $saveProduct->handle([
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'product_category_id' => $item['category'] ? $categories[$item['category']] : null,
                'sku' => $item['sku'],
                'price' => $item['price'],
                'compare_at_price' => $item['compare_at_price'] ?? null,
                'is_active' => true,
                'track_stock' => $item['stock'] !== null,
                'opening_stock' => $item['stock'],
            ], actor: $owner)]);

            $kavya = Customer::query()->where('name', 'Kavya Rao')->first();
            $customer = fn (?Customer $known, string $name, string $phone) => $known ? ['customer_id' => $known->id] : ['customer' => ['name' => $name, 'phone' => $phone]];
            $line = fn (string $name, int $quantity) => ['product_id' => $products[$name]->id, 'quantity' => $quantity];

            // A counter sale, paid in full.
            $placeOrder->handle([
                ...$customer($kavya, 'Kavya Rao', '98765 10001'),
                'items' => [$line('Argan oil hair serum', 1), $line('Keratin shampoo', 1)],
                'fulfilment' => 'in_store',
                'completed' => true,
                'payment' => ['amount' => 1130, 'method' => 'upi', 'reference' => 'UPI-DEMO-1'],
            ], $owner);

            // A phone order waiting for pickup, part paid.
            $pickup = $placeOrder->handle([
                'customer' => ['name' => 'Ananya Iyer', 'phone' => '98765 40001'],
                'items' => [$line('Vitamin C face serum', 1), $line('Nail care kit', 1)],
                'fulfilment' => 'pickup',
                'payment' => ['amount' => 500, 'method' => 'cash'],
            ], $owner);
            $changeStatus->handle($pickup, OrderStatus::Ready, $owner);

            // A website order waiting for confirmation.
            $placeOrder->handle([
                'customer' => ['name' => 'Neha Kulkarni', 'phone' => '98765 40002', 'email' => 'neha@example.com'],
                'items' => [$line('Deep conditioning mask', 1), $line('Argan oil hair serum', 1)],
                'fulfilment' => 'pickup',
                'notes' => 'Will collect after 6 pm.',
                'source' => 'website',
            ]);

            // A cancelled order: its stock went back.
            $cancelled = $placeOrder->handle([
                'customer' => ['name' => 'Rahul Shah', 'phone' => '98765 40003'],
                'items' => [$line('Keratin shampoo', 2)],
                'fulfilment' => 'in_store',
            ], $owner);
            $changeStatus->handle($cancelled, OrderStatus::Cancelled, $owner, 'Customer bought elsewhere');
        });
    }
}
