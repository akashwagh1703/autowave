<?php

namespace Database\Seeders;

use App\Domain\Commerce\Actions\ChangeOrderStatus;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\SaveCoupon;
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
 * Local demo products, stock and orders for ABC Salon and ABC Store, created through the domain
 * actions. Runs once per tenant (skips if the tenant already has products).
 */
class DemoCommerceSeeder extends Seeder
{
    public function run(TenantContext $context, SaveProductCategory $saveCategory, SaveProduct $saveProduct, PlaceOrder $placeOrder, ChangeOrderStatus $changeStatus, SaveCoupon $saveCoupon): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCommerceSeeder must not run in production.');
        }

        if ($store = Tenant::query()->where('slug', 'abc-store')->first()) {
            $context->run($store, fn () => $this->store($saveCategory, $saveProduct, $placeOrder, $changeStatus, $saveCoupon));
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

    /** ABC Store (Local Commerce): groceries, coupons, repeat customers and a delivery with a coupon. */
    private function store(SaveProductCategory $saveCategory, SaveProduct $saveProduct, PlaceOrder $placeOrder, ChangeOrderStatus $changeStatus, SaveCoupon $saveCoupon): void
    {
        if (Product::withTrashed()->exists()) {
            return;
        }

        $owner = User::query()->where('email', 'owner@abc-store.test')->first();
        $categories = collect(['Staples', 'Dairy', 'Snacks', 'Household'])->mapWithKeys(fn (string $name) => [$name => $saveCategory->handle($name)->id]);

        $products = collect([
            ['Basmati rice 5 kg', 'Staples', 'ST-RICE-5', 699, 25],
            ['Toor dal 1 kg', 'Staples', 'ST-TOOR-1', 165, 40],
            ['Sunflower oil 1 L', 'Staples', 'ST-OIL-1', 155, 3],
            ['Milk 1 L', 'Dairy', 'DY-MILK-1', 66, 60],
            ['Paneer 200 g', 'Dairy', 'DY-PNR-200', 95, 12],
            ['Masala chips', 'Snacks', 'SN-CHIPS', 20, 80],
            ['Dishwash liquid 500 ml', 'Household', 'HH-DISH-500', 110, 0],
        ])->mapWithKeys(fn (array $item) => [$item[0] => $saveProduct->handle([
            'name' => $item[0],
            'product_category_id' => $categories[$item[1]],
            'sku' => $item[2],
            'price' => $item[3],
            'is_active' => true,
            'track_stock' => true,
            'opening_stock' => $item[4],
            'low_stock_threshold' => 5,
        ], actor: $owner)]);

        $saveCoupon->handle(['code' => 'SAVE50', 'description' => '₹50 off orders over ₹999', 'type' => 'fixed', 'value' => 50, 'min_subtotal' => 999, 'online' => true, 'is_active' => true]);
        $saveCoupon->handle(['code' => 'DIWALI15', 'description' => '15% off, up to ₹200', 'type' => 'percent', 'value' => 15, 'max_discount' => 200, 'usage_limit' => 50, 'online' => true, 'is_active' => true]);

        $line = fn (string $name, int $quantity) => ['product_id' => $products[$name]->id, 'quantity' => $quantity];
        $meena = ['customer' => ['name' => 'Meena Gupta', 'phone' => '95000 10001']];

        // Meena is a repeat customer: two counter sales.
        $placeOrder->handle([...$meena, 'items' => [$line('Milk 1 L', 2), $line('Paneer 200 g', 1)], 'fulfilment' => 'in_store', 'completed' => true, 'payment' => ['amount' => 227, 'method' => 'cash']], $owner);
        $placeOrder->handle([...$meena, 'items' => [$line('Toor dal 1 kg', 2), $line('Masala chips', 4)], 'fulfilment' => 'in_store', 'completed' => true, 'payment' => ['amount' => 410, 'method' => 'upi']], $owner);

        // A website delivery with a coupon, waiting for confirmation.
        $placeOrder->handle([
            'customer' => ['name' => 'Arjun Bhat', 'phone' => '95000 10002'],
            'items' => [$line('Basmati rice 5 kg', 1), $line('Sunflower oil 1 L', 2), $line('Milk 1 L', 1)],
            'fulfilment' => 'delivery',
            'delivery_address' => 'Flat 12, Sunshine Apartments, Aundh',
            'coupon_code' => 'SAVE50',
            'source' => 'website',
        ]);

        // A pickup order ready for collection.
        $pickup = $placeOrder->handle([
            'customer' => ['name' => 'Lata Iyer', 'phone' => '95000 10003'],
            'items' => [$line('Masala chips', 6), $line('Paneer 200 g', 2)],
            'fulfilment' => 'pickup',
        ], $owner);
        $changeStatus->handle($pickup, OrderStatus::Ready, $owner);
    }
}
