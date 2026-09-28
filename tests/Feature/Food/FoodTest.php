<?php

namespace Tests\Feature\Food;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Product;
use App\Domain\Food\Actions\BookReservation;
use App\Domain\Food\Actions\SaveDiningTable;
use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Food\Models\Reservation;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Cafes (food engine, ADR-020): tables, reservations, dine-in orders and the kitchen screen. */
class FoodTest extends TestCase
{
    use CreatesBookingRecords, CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const CAFE = 'abc-cafe.autowave.test';

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 5 October 2026, 10:00 in India.
        $this->travelToBookingDay();
    }

    private function cafe(string $name = 'ABC Cafe'): Tenant
    {
        return $this->createTenant($name, 'cafe');
    }

    private function table(Tenant $tenant, string $name = 'T1', int $seats = 4): DiningTable
    {
        return $this->inTenant($tenant, fn () => app(SaveDiningTable::class)->handle(['name' => $name, 'seats' => $seats]));
    }

    /** @param  array<string, mixed>  $data */
    private function reserve(Tenant $tenant, string $local, array $data = []): Reservation
    {
        return $this->inTenant($tenant, fn () => app(BookReservation::class)->handle([
            'customer' => ['name' => 'Guest '.uniqid(), 'phone' => null],
            'party_size' => 2,
            'reserved_at' => $this->local($tenant, $local),
            ...$data,
        ]));
    }

    public function test_the_owner_manages_tables(): void
    {
        $tenant = $this->cafe();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/tables'), ['name' => 'T1', 'seats' => 4, 'area' => 'Indoor', 'is_active' => true])->assertSessionHasNoErrors();
        $this->post($this->appUrl('/tables'), ['name' => 't1', 'seats' => 2, 'is_active' => true])->assertSessionHasErrors('name');
        $table = $this->inTenant($tenant, fn () => DiningTable::query()->sole());

        $this->placeOrder($tenant, [[$this->makeProduct($tenant), 1]], ['fulfilment' => 'dine_in', 'dining_table_id' => $table->id]);
        $this->delete($this->appUrl("/tables/{$table->id}"))->assertSessionHasErrors('table');

        $free = $this->table($tenant, 'G1');
        $this->delete($this->appUrl("/tables/{$free->id}"))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('dining_tables', ['id' => $free->id]);

        $this->get($this->appUrl('/tables'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/tables/Index')
            ->where('tables.0.name', 'T1')
            ->where('tables.0.orders.0.id', fn ($id) => $id !== null));
    }

    public function test_the_team_books_tables_without_double_booking(): void
    {
        $tenant = $this->cafe();
        $t1 = $this->table($tenant, 'T1');
        $t2 = $this->table($tenant, 'T2');
        $this->actingAs($this->ownerOf($tenant));

        $book = fn (array $data) => $this->post($this->appUrl('/reservations'), [
            'customer' => ['name' => 'Farah Khan', 'phone' => '96000 10001'],
            'reserved_at' => '2026-10-05T19:00', 'duration_minutes' => 90, 'party_size' => 4, 'dining_table_id' => $t1->id,
            ...$data,
        ]);

        $book([])->assertSessionHasNoErrors()->assertRedirect($this->appUrl('/reservations?date=2026-10-05'));
        $book(['reserved_at' => '2026-10-05T20:00'])->assertSessionHasErrors(['dining_table_id' => 'This table is already booked at that time.']);
        $book(['reserved_at' => '2026-10-05T20:30'])->assertSessionHasNoErrors();
        $book(['reserved_at' => '2026-10-05T20:00', 'dining_table_id' => $t2->id])->assertSessionHasNoErrors();
        $book(['reserved_at' => '2026-10-01T19:00'])->assertSessionHasErrors('reserved_at');

        $reservations = $this->inTenant($tenant, fn () => Reservation::query()->orderBy('id')->get());
        $this->assertCount(3, $reservations);
        $this->assertSame([ReservationStatus::Confirmed, 'manual', 'Farah Khan'], [$reservations[0]->status, $reservations[0]->source, $reservations[0]->customer->name]);
        $this->assertSame(1, $this->inTenant($tenant, fn () => $reservations[0]->customer->newQuery()->count()), 'The same phone reuses the customer.');

        $this->get($this->appUrl('/reservations?date=2026-10-05'))->assertInertia(fn (Assert $page) => $page
            ->has('reservations', 3)
            ->where('counts.guests', 12));
    }

    public function test_reservations_move_through_their_lifecycle(): void
    {
        $tenant = $this->cafe();
        $reservation = $this->reserve($tenant, '2026-10-05 19:00');
        $other = $this->reserve($tenant, '2026-10-05 20:00');
        $this->actingAs($this->ownerOf($tenant));

        $this->patch($this->appUrl("/reservations/{$reservation->id}/status"), ['status' => 'seated'])->assertSessionHasErrors('status');

        $this->travelToBookingDay('2026-10-05 18:30');
        $this->patch($this->appUrl("/reservations/{$reservation->id}/status"), ['status' => 'seated'])->assertSessionHasNoErrors();
        $this->patch($this->appUrl("/reservations/{$reservation->id}/status"), ['status' => 'cancelled'])->assertSessionHasErrors('status');
        $this->patch($this->appUrl("/reservations/{$reservation->id}/status"), ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->patch($this->appUrl("/reservations/{$other->id}/status"), ['status' => 'cancelled', 'reason' => 'Plans changed'])->assertSessionHasNoErrors();

        $this->assertSame(ReservationStatus::Completed, $this->inTenant($tenant, fn () => $reservation->fresh()->status));
        $cancelled = $this->inTenant($tenant, fn () => $other->fresh());
        $this->assertSame([ReservationStatus::Cancelled, 'Plans changed'], [$cancelled->status, $cancelled->cancellation_reason]);

        $this->get($this->appUrl("/reservations/{$reservation->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/reservations/Show')
            ->where('activities', fn ($activities) => collect($activities)->pluck('type')->contains('reservation_seated')));
    }

    public function test_visitors_request_tables_on_the_website(): void
    {
        $tenant = $this->cafe();

        $slots = $this->getJson($this->siteUrl(self::CAFE, '/reservations/slots?date=2026-10-05'))->assertOk()->json('slots');
        $this->assertSame(['11:00', '11:30'], array_slice(array_column($slots, 'time'), 0, 2));
        $this->assertSame('21:30', last($slots)['time']);

        $request = fn (array $data) => $this->from($this->siteUrl(self::CAFE))->post($this->siteUrl(self::CAFE, '/reservations'), [
            'name' => 'Meera Joshi', 'phone' => '99887 76655', 'party_size' => 3, 'starts_at' => $slots[2]['starts_at'], 'notes' => 'Window seat',
            ...$data,
        ]);

        $request(['party_size' => 20])->assertSessionHasErrors('party_size');
        $request(['starts_at' => $this->local($tenant, '2026-10-05 11:10')->toIso8601String()])->assertSessionHasErrors('starts_at');
        $request(['company_website' => 'spam'])->assertRedirect();
        $this->assertSame(0, $this->inTenant($tenant, fn () => Reservation::query()->count()));

        $request([])->assertSessionHasNoErrors()->assertRedirect($this->siteUrl(self::CAFE));

        $reservation = $this->inTenant($tenant, fn () => Reservation::query()->with('customer')->sole());
        $this->assertSame([ReservationStatus::Pending, 'website', null, 3, 'Meera Joshi'], [$reservation->status, $reservation->source, $reservation->dining_table_id, $reservation->party_size, $reservation->customer->name]);
        $this->get($this->siteUrl(self::CAFE))->assertInertia(fn (Assert $page) => $page
            ->where('reservationConfirmation.party_size', 3)
            ->where('reservationConfirmation.status', 'pending')
            ->where('reservation.max_party_size', 12));

        $this->actingAs($this->ownerOf($tenant));
        $this->put($this->appUrl('/settings/food'), [
            'online' => false, 'auto_confirm' => true, 'duration_minutes' => 90, 'opens' => '11:00', 'closes' => '22:00',
            'slot_interval' => 30, 'max_party_size' => 12, 'min_notice_minutes' => 60, 'max_days_ahead' => 30,
        ])->assertSessionHasNoErrors();
        $this->getJson($this->siteUrl(self::CAFE, '/reservations/slots?date=2026-10-05'))->assertNotFound();
        $this->get($this->siteUrl(self::CAFE))->assertInertia(fn (Assert $page) => $page
            ->where('reservation', null)
            ->where('sections', fn ($sections) => ! collect($sections)->pluck('type')->contains('reservation')));
    }

    public function test_food_settings_are_validated(): void
    {
        $tenant = $this->cafe();
        $this->actingAs($this->ownerOf($tenant));
        $valid = ['online' => true, 'auto_confirm' => true, 'duration_minutes' => 60, 'opens' => '08:00', 'closes' => '23:00', 'slot_interval' => 15, 'max_party_size' => 6, 'min_notice_minutes' => 0, 'max_days_ahead' => 14];

        $this->put($this->appUrl('/settings/food'), [...$valid, 'closes' => '07:00'])->assertSessionHasErrors('closes');
        $this->put($this->appUrl('/settings/food'), [...$valid, 'duration_minutes' => 77])->assertSessionHasErrors('duration_minutes');
        $this->put($this->appUrl('/settings/food'), $valid)->assertSessionHasNoErrors();

        $this->get($this->appUrl('/settings/food'))->assertInertia(fn (Assert $page) => $page->where('settings.max_party_size', 6)->where('settings.opens', '08:00'));
    }

    public function test_dine_in_orders_go_through_the_kitchen_and_can_take_more_items(): void
    {
        $tenant = $this->cafe();
        $table = $this->table($tenant, 'T2');
        $coffee = $this->makeProduct($tenant, ['name' => 'Cappuccino', 'price' => '180.00', 'food_type' => 'veg']);
        $sandwich = $this->makeProduct($tenant, ['name' => 'Club sandwich', 'price' => '360.00', 'food_type' => 'non_veg']);
        $soldOut = $this->makeProduct($tenant, ['name' => 'Butter chicken', 'is_available' => false]);
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/orders'), ['items' => [['product_id' => $coffee->id, 'quantity' => 1]], 'fulfilment' => 'in_store'])->assertSessionHasErrors('customer.name');
        $this->post($this->appUrl('/orders'), ['items' => [['product_id' => $soldOut->id, 'quantity' => 1]], 'fulfilment' => 'dine_in', 'dining_table_id' => $table->id])->assertSessionHasErrors('items');
        $this->post($this->appUrl('/orders'), ['items' => [['product_id' => $coffee->id, 'quantity' => 2]], 'fulfilment' => 'dine_in', 'dining_table_id' => $table->id, 'notes' => 'Oat milk'])
            ->assertSessionHasNoErrors();

        $order = $this->inTenant($tenant, fn () => Order::query()->sole());
        $this->assertSame([null, $table->id, 'dine_in', OrderStatus::Confirmed], [$order->customer_id, $order->dining_table_id, $order->fulfilment, $order->status]);

        $this->get($this->appUrl('/kitchen'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('tickets', 1)
            ->where('tickets.0.table', 'T2')
            ->where('tickets.0.notes', 'Oat milk')
            ->where('tickets.0.items.0.name', 'Cappuccino'));

        $this->post($this->appUrl("/kitchen/{$order->id}/ready"))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Ready, $this->inTenant($tenant, fn () => $order->fresh()->status));
        $this->post($this->appUrl("/kitchen/{$order->id}/ready"))->assertSessionHasErrors('order');
        $this->get($this->appUrl('/kitchen'))->assertInertia(fn (Assert $page) => $page->has('tickets', 0));

        $this->post($this->appUrl("/orders/{$order->id}/items"), ['items' => [['product_id' => $sandwich->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $fresh = $this->inTenant($tenant, fn () => $order->fresh());
        $this->assertSame(['720.00', '720.00'], [(string) $fresh->subtotal, (string) $fresh->total]);
        $this->assertSame([OrderItem::KITCHEN_READY, OrderItem::KITCHEN_QUEUED], $this->inTenant($tenant, fn () => OrderItem::query()->where('order_id', $order->id)->orderBy('id')->pluck('kitchen_status')->all()));
        $this->get($this->appUrl('/kitchen'))->assertInertia(fn (Assert $page) => $page->has('tickets', 1)->where('tickets.0.items.0.name', 'Club sandwich'));

        $pickup = $this->placeOrder($tenant, [[$coffee, 1]], ['fulfilment' => 'pickup']);
        $this->post($this->appUrl("/orders/{$pickup->id}/items"), ['items' => [['product_id' => $coffee->id, 'quantity' => 1]]])->assertSessionHasErrors('items');

        $this->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page->where('metrics.kitchen_queue.value', 2));
    }

    public function test_menu_items_have_food_types_and_can_be_sold_out(): void
    {
        $tenant = $this->cafe();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/products'), ['name' => 'Masala omelette', 'price' => 240, 'is_active' => true, 'track_stock' => false, 'food_type' => 'egg', 'is_available' => true])
            ->assertSessionHasNoErrors();
        $product = $this->inTenant($tenant, fn () => Product::query()->sole());
        $this->assertSame(['egg', true], [$product->food_type, $product->is_available]);

        $this->post($this->appUrl('/products/bulk'), ['action' => 'unavailable', 'ids' => [$product->id]])->assertSessionHasNoErrors();
        $this->assertFalse($this->inTenant($tenant, fn () => $product->fresh()->is_available));

        $this->get($this->siteUrl(self::CAFE))->assertInertia(fn (Assert $page) => $page->where('sections', function ($sections) {
            $item = collect($sections)->firstWhere('type', 'products')['data'][0]['products'][0];

            return $item['food_type'] === 'egg' && $item['in_stock'] === false;
        }));
    }

    public function test_staff_can_see_reservations_but_not_change_them(): void
    {
        $tenant = $this->cafe();
        $reservation = $this->reserve($tenant, '2026-10-05 19:00');
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->actingAs($staff);

        $this->get($this->appUrl('/reservations'))->assertOk();
        $this->get($this->appUrl('/tables'))->assertOk();
        $this->post($this->appUrl('/tables'), ['name' => 'X', 'seats' => 2, 'is_active' => true])->assertForbidden();
        $this->patch($this->appUrl("/reservations/{$reservation->id}/status"), ['status' => 'cancelled'])->assertForbidden();
        $this->put($this->appUrl('/settings/food'), [])->assertForbidden();
    }

    public function test_food_pages_are_hidden_without_the_engine(): void
    {
        $salon = $this->createTenant();
        $product = $this->makeProduct($salon);
        $this->actingAs($this->ownerOf($salon));

        foreach (['/tables', '/reservations', '/kitchen', '/settings/food'] as $path) {
            $this->get($this->appUrl($path))->assertNotFound();
        }

        $this->post($this->appUrl('/orders'), ['customer' => ['name' => 'Walk-in'], 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'fulfilment' => 'dine_in'])
            ->assertSessionHasErrors('fulfilment');
        $this->getJson($this->siteUrl('abc-salon.autowave.test', '/reservations/slots?date=2026-10-05'))->assertNotFound();
    }
}
