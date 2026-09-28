<?php

namespace Database\Seeders;

use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\SaveCoupon;
use App\Domain\Commerce\Actions\SaveProduct;
use App\Domain\Commerce\Actions\SaveProductCategory;
use App\Domain\Food\Actions\BookReservation;
use App\Domain\Food\Actions\SaveDiningTable;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo menu, tables, reservations, dine-in orders and a coupon for ABC Cafe, created through
 * the domain actions. Runs once (skips if the cafe already has tables).
 */
class DemoFoodSeeder extends Seeder
{
    public function run(
        TenantContext $context,
        SaveProductCategory $saveCategory,
        SaveProduct $saveProduct,
        SaveDiningTable $saveTable,
        BookReservation $book,
        PlaceOrder $placeOrder,
        SaveCoupon $saveCoupon,
    ): void {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoFoodSeeder must not run in production.');
        }

        $cafe = Tenant::query()->where('slug', 'abc-cafe')->first();

        if (! $cafe) {
            return;
        }

        $context->run($cafe, function () use ($saveCategory, $saveProduct, $saveTable, $book, $placeOrder, $saveCoupon) {
            if (DiningTable::withTrashed()->exists()) {
                return;
            }

            $owner = User::query()->where('email', 'owner@abc-cafe.test')->first();
            $categories = collect(['Coffee', 'Breakfast', 'Mains', 'Desserts'])->mapWithKeys(fn (string $name) => [$name => $saveCategory->handle($name)->id]);

            $menu = collect([
                ['Cappuccino', 'Coffee', 180, 'veg', true],
                ['Cold brew', 'Coffee', 220, 'veg', true],
                ['Masala omelette toast', 'Breakfast', 240, 'egg', true],
                ['Avocado toast', 'Breakfast', 320, 'veg', true],
                ['Chicken club sandwich', 'Mains', 360, 'non_veg', true],
                ['Paneer tikka wrap', 'Mains', 290, 'veg', true],
                ['Butter chicken bowl', 'Mains', 420, 'non_veg', false],
                ['Chocolate brownie', 'Desserts', 190, 'egg', true],
            ])->mapWithKeys(fn (array $item) => [$item[0] => $saveProduct->handle([
                'name' => $item[0],
                'product_category_id' => $categories[$item[1]],
                'price' => $item[2],
                'food_type' => $item[3],
                'is_available' => $item[4],
                'is_active' => true,
                'track_stock' => false,
            ], actor: $owner)]);

            $tables = collect([
                ['T1', 2, 'Indoor'], ['T2', 4, 'Indoor'], ['T3', 4, 'Indoor'], ['T4', 6, 'Indoor'],
                ['G1', 4, 'Garden'], ['G2', 8, 'Garden'],
            ])->mapWithKeys(fn (array $table) => [$table[0] => $saveTable->handle(['name' => $table[0], 'seats' => $table[1], 'area' => $table[2]])]);

            $saveCoupon->handle(['code' => 'WELCOME10', 'description' => '10% off your first order', 'type' => 'percent', 'value' => 10, 'max_discount' => 150, 'online' => true, 'is_active' => true]);
            $saveCoupon->handle(['code' => 'FLAT50', 'description' => '₹50 off orders over ₹500', 'type' => 'fixed', 'value' => 50, 'min_subtotal' => 500, 'usage_limit' => 100, 'online' => false, 'is_active' => true]);

            $line = fn (string $name, int $quantity) => ['product_id' => $menu[$name]->id, 'quantity' => $quantity];
            $today = TenantTime::now()->startOfDay();

            $book->handle([
                'customer' => ['name' => 'Farah Khan', 'phone' => '96000 10001'],
                'party_size' => 4,
                'reserved_at' => $today->copy()->setTime(20, 0)->utc(),
                'dining_table_id' => $tables['G1']->id,
                'notes' => 'Birthday: please keep the corner table.',
            ], $owner);
            $book->handle([
                'customer' => ['name' => 'Aditya Rane', 'phone' => '96000 10002'],
                'party_size' => 2,
                'reserved_at' => $today->copy()->addDay()->setTime(13, 0)->utc(),
            ], $owner);

            // A seated walk-in with an order in the kitchen.
            $book->handle([
                'customer' => ['name' => 'Gauri Patil', 'phone' => '96000 10003'],
                'party_size' => 3,
                'reserved_at' => now(),
                'dining_table_id' => $tables['T2']->id,
                'seated' => true,
            ], $owner);
            $placeOrder->handle([
                'customer' => ['name' => 'Gauri Patil', 'phone' => '96000 10003'],
                'items' => [$line('Cappuccino', 2), $line('Avocado toast', 1), $line('Chicken club sandwich', 1)],
                'fulfilment' => 'dine_in',
                'dining_table_id' => $tables['T2']->id,
                'notes' => 'One cappuccino with oat milk.',
            ], $owner);

            // A paid walk-in (no customer details) and an online pickup with a coupon.
            $placeOrder->handle([
                'items' => [$line('Cold brew', 1), $line('Chocolate brownie', 1)],
                'fulfilment' => 'dine_in',
                'dining_table_id' => $tables['T1']->id,
                'completed' => true,
                'payment' => ['amount' => 410, 'method' => 'upi'],
            ], $owner);
            $placeOrder->handle([
                'customer' => ['name' => 'Rohit Nair', 'phone' => '96000 10004'],
                'items' => [$line('Paneer tikka wrap', 2), $line('Cappuccino', 2)],
                'fulfilment' => 'pickup',
                'coupon_code' => 'WELCOME10',
                'source' => 'website',
            ]);
        });
    }
}
