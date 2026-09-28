<?php

namespace Tests\Feature\Food;

use App\Domain\Commerce\Models\Order;
use App\Domain\Food\Actions\BookReservation;
use App\Domain\Food\Actions\SaveDiningTable;
use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Food\Models\Reservation;
use App\Domain\Tenant\Exceptions\MissingTenantContext;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Mandatory cross-tenant tests (master prompt §86) for tables, reservations, dine-in orders and the kitchen. */
class FoodIsolationTest extends TestCase
{
    use CreatesBookingRecords, CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    private function table(Tenant $tenant, string $name = 'T1'): DiningTable
    {
        return $this->inTenant($tenant, fn () => app(SaveDiningTable::class)->handle(['name' => $name, 'seats' => 4]));
    }

    private function reserve(Tenant $tenant, ?DiningTable $table = null): Reservation
    {
        return $this->inTenant($tenant, fn () => app(BookReservation::class)->handle([
            'customer' => ['name' => 'Their guest', 'phone' => '96000 20001'],
            'party_size' => 2,
            'reserved_at' => $this->local($tenant, '2026-10-05 19:00'),
            'dining_table_id' => $table?->id,
        ]));
    }

    public function test_tenant_a_cannot_touch_tenant_b_tables_reservations_or_kitchen(): void
    {
        $a = $this->createTenant('Tenant A', 'cafe');
        $b = $this->createTenant('Tenant B', 'cafe');
        $theirTable = $this->table($b);
        $theirReservation = $this->reserve($b, $theirTable);
        $theirOrder = $this->placeOrder($b, [[$this->makeProduct($b), 1]], ['fulfilment' => 'dine_in', 'dining_table_id' => $theirTable->id]);
        $this->actingAs($this->ownerOf($a));

        $this->put($this->appUrl("/tables/{$theirTable->id}"), ['name' => 'Hijacked', 'seats' => 1, 'is_active' => true])->assertNotFound();
        $this->delete($this->appUrl("/tables/{$theirTable->id}"))->assertNotFound();
        $this->get($this->appUrl("/reservations/{$theirReservation->id}"))->assertNotFound();
        $this->put($this->appUrl("/reservations/{$theirReservation->id}"), ['reserved_at' => '2026-10-05T20:00', 'duration_minutes' => 60, 'party_size' => 2])->assertNotFound();
        $this->patch($this->appUrl("/reservations/{$theirReservation->id}/status"), ['status' => 'cancelled'])->assertNotFound();
        $this->post($this->appUrl("/kitchen/{$theirOrder->id}/ready"))->assertNotFound();
        $this->post($this->appUrl("/orders/{$theirOrder->id}/items"), ['items' => [['product_id' => 1, 'quantity' => 1]]])->assertNotFound();

        $this->get($this->appUrl('/tables'))->assertInertia(fn (Assert $page) => $page->where('tables', []));
        $this->get($this->appUrl('/reservations?date=2026-10-05&view=all'))->assertInertia(fn (Assert $page) => $page->where('reservations', []));
        $this->get($this->appUrl('/kitchen'))->assertInertia(fn (Assert $page) => $page->where('tickets', []));
        $this->assertSame([], $this->getJson($this->appUrl('/reservations/customers?search=Their'))->assertOk()->json('data'));

        $this->assertSame('T1', DiningTable::withoutTenantScope()->findOrFail($theirTable->id)->name);
        $this->assertSame(ReservationStatus::Confirmed, Reservation::withoutTenantScope()->findOrFail($theirReservation->id)->status);
    }

    public function test_tenant_a_cannot_use_tenant_b_tables_or_customers(): void
    {
        $a = $this->createTenant('Tenant A', 'cafe');
        $b = $this->createTenant('Tenant B', 'cafe');
        $theirTable = $this->table($b);
        $theirCustomer = $this->makeCustomer($b);
        $mine = $this->makeProduct($a);
        $myReservation = $this->reserve($a);
        $this->actingAs($this->ownerOf($a));

        $this->post($this->appUrl('/reservations'), ['customer' => ['name' => 'Mine'], 'reserved_at' => '2026-10-05T19:00', 'duration_minutes' => 90, 'party_size' => 2, 'dining_table_id' => $theirTable->id])
            ->assertSessionHasErrors('dining_table_id');
        $this->post($this->appUrl('/reservations'), ['customer_id' => $theirCustomer->id, 'reserved_at' => '2026-10-05T19:00', 'duration_minutes' => 90, 'party_size' => 2])
            ->assertSessionHasErrors('customer_id');
        $this->put($this->appUrl("/reservations/{$myReservation->id}"), ['reserved_at' => '2026-10-05T19:00', 'duration_minutes' => 90, 'party_size' => 2, 'dining_table_id' => $theirTable->id])
            ->assertSessionHasErrors('dining_table_id');
        $this->post($this->appUrl('/orders'), ['items' => [['product_id' => $mine->id, 'quantity' => 1]], 'fulfilment' => 'dine_in', 'dining_table_id' => $theirTable->id])
            ->assertSessionHasErrors('dining_table_id');

        $this->assertSame(1, Reservation::withoutTenantScope()->where('tenant_id', $a->id)->count());
        $this->assertNull(Reservation::withoutTenantScope()->findOrFail($myReservation->id)->dining_table_id);
        $this->assertSame(0, Order::withoutTenantScope()->where('tenant_id', $a->id)->count());
    }

    public function test_composite_foreign_keys_reject_cross_tenant_references(): void
    {
        $a = $this->createTenant('Tenant A', 'cafe');
        $b = $this->createTenant('Tenant B', 'cafe');
        $reservation = $this->reserve($a);
        $order = $this->placeOrder($a, [[$this->makeProduct($a), 1]]);
        $theirTable = $this->table($b);
        $theirCustomer = $this->makeCustomer($b);

        $attempts = [
            fn () => DB::table('reservations')->where('id', $reservation->id)->update(['dining_table_id' => $theirTable->id]),
            fn () => DB::table('reservations')->where('id', $reservation->id)->update(['customer_id' => $theirCustomer->id]),
            fn () => DB::table('orders')->where('id', $order->id)->update(['fulfilment' => 'dine_in', 'dining_table_id' => $theirTable->id]),
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

    public function test_food_models_fail_closed_without_a_tenant(): void
    {
        $tenant = $this->createTenant('Tenant A', 'cafe');
        $this->reserve($tenant, $this->table($tenant));

        $this->assertSame(0, DiningTable::query()->count());
        $this->assertSame(0, Reservation::query()->count());

        $this->expectException(MissingTenantContext::class);
        DiningTable::query()->create(['name' => 'Orphan', 'seats' => 2, 'sort_order' => 1]);
    }
}
