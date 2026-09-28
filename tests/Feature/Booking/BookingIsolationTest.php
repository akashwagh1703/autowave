<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Models\TimeOff;
use App\Domain\Service\Models\Service;
use App\Domain\Service\Models\ServiceCategory;
use App\Domain\Tenant\Exceptions\CrossTenantWrite;
use App\Domain\Tenant\Exceptions\MissingTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Mandatory cross-tenant tests (master prompt §86) for services and booking.
 */
class BookingIsolationTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_tenant_a_cannot_access_tenant_b_appointment(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirResource = $this->makeResource($b);
        $theirs = $this->book($b, $theirResource, '2026-10-06 11:00', ['notes' => 'Secret note']);
        $this->actingAs($this->ownerOf($a));

        $this->get($this->appUrl("/appointments/{$theirs->id}"))->assertNotFound();
        $this->put($this->appUrl("/appointments/{$theirs->id}"), ['notes' => 'Hijacked'])->assertNotFound();
        $this->patch($this->appUrl("/appointments/{$theirs->id}/status"), ['status' => 'cancelled'])->assertNotFound();
        $this->patch($this->appUrl("/appointments/{$theirs->id}/reschedule"), ['starts_at' => '2026-10-06T15:00'])->assertNotFound();

        $this->post($this->appUrl('/appointments/bulk'), ['action' => 'cancelled', 'ids' => [$theirs->id]])->assertRedirect();

        $fresh = Appointment::withoutTenantScope()->findOrFail($theirs->id);
        $this->assertSame(AppointmentStatus::Confirmed, $fresh->status);
        $this->assertSame('Secret note', $fresh->notes);
        $this->assertSame('2026-10-06T05:30:00+00:00', $fresh->starts_at->utc()->toIso8601String());
    }

    public function test_tenant_b_appointments_never_appear_in_tenant_a_lists(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $this->book($b, $this->makeResource($b), '2026-10-06 11:00', ['customer' => ['name' => 'Their Customer']]);
        $this->book($a, $this->makeResource($a), '2026-10-06 11:00', ['customer' => ['name' => 'My Customer']]);
        $this->actingAs($this->ownerOf($a));

        $this->get($this->appUrl('/appointments/list?range=all'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('appointments.meta.total', 1)
                ->where('appointments.data.0.customer.name', 'My Customer'));

        $this->get($this->appUrl('/appointments?date=2026-10-06'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 1)->has('resources', 1));

        $this->getJson($this->appUrl('/appointments/customers?search=Their'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_tenant_a_cannot_book_with_tenant_b_resource_service_or_customer(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $mine = $this->makeResource($a);
        $myCustomer = $this->makeCustomer($a);
        $theirResource = $this->makeResource($b);
        $theirService = $this->makeService($b, [], [$theirResource]);
        $theirCustomer = $this->makeCustomer($b);
        $this->actingAs($this->ownerOf($a));

        $payload = fn (array $overrides) => [
            'customer_id' => $myCustomer->id,
            'booking_resource_id' => $mine->id,
            'starts_at' => '2026-10-06T11:00',
            'duration_minutes' => 60,
            ...$overrides,
        ];

        $this->post($this->appUrl('/appointments'), $payload(['booking_resource_id' => $theirResource->id]))->assertSessionHasErrors('booking_resource_id');
        $this->post($this->appUrl('/appointments'), $payload(['service_id' => $theirService->id]))->assertSessionHasErrors('service_id');
        $this->post($this->appUrl('/appointments'), $payload(['customer_id' => $theirCustomer->id]))->assertSessionHasErrors('customer_id');
        $this->patch($this->appUrl('/appointments/'.$this->book($a, $mine, '2026-10-07 11:00')->id.'/reschedule'), [
            'starts_at' => '2026-10-07T12:00',
            'booking_resource_id' => $theirResource->id,
        ])->assertSessionHasErrors('booking_resource_id');

        $this->getJson($this->appUrl("/appointments/availability?resource={$theirResource->id}&date=2026-10-06"))->assertNotFound();
        $this->assertSame(0, Appointment::withoutTenantScope()->where('booking_resource_id', $theirResource->id)->count());
    }

    public function test_tenant_a_cannot_manage_tenant_b_services_or_resources(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirResource = $this->makeResource($b, ['name' => 'Their Stylist']);
        $theirService = $this->makeService($b, ['name' => 'Their Service']);
        $theirCategory = $this->inTenant($b, fn () => ServiceCategory::query()->firstOrFail());
        $theirTimeOff = $this->inTenant($b, fn () => TimeOff::query()->create([
            'booking_resource_id' => $theirResource->id,
            'starts_at' => $this->local($b, '2026-10-10 00:00'),
            'ends_at' => $this->local($b, '2026-10-11 00:00'),
        ]));
        $this->actingAs($this->ownerOf($a));

        $this->get($this->appUrl("/resources/{$theirResource->id}"))->assertNotFound();
        $this->get($this->appUrl("/resources/{$theirResource->id}/edit"))->assertNotFound();
        $this->put($this->appUrl("/resources/{$theirResource->id}"), ['name' => 'Hijacked', 'color' => '#000000', 'is_active' => true, 'working_hours' => []])->assertNotFound();
        $this->post($this->appUrl("/resources/{$theirResource->id}/time-off"), ['starts_at' => '2026-10-12T00:00', 'ends_at' => '2026-10-13T00:00'])->assertNotFound();
        $this->delete($this->appUrl("/resources/{$theirResource->id}/time-off/{$theirTimeOff->id}"))->assertNotFound();
        $this->delete($this->appUrl("/resources/{$theirResource->id}"))->assertNotFound();

        $this->get($this->appUrl("/services/{$theirService->id}/edit"))->assertNotFound();
        $this->put($this->appUrl("/services/{$theirService->id}"), ['name' => 'Hijacked', 'duration_minutes' => 30, 'price' => 1, 'is_active' => true])->assertNotFound();
        $this->delete($this->appUrl("/services/{$theirService->id}"))->assertNotFound();
        $this->put($this->appUrl("/service-categories/{$theirCategory->id}"), ['name' => 'Hijacked'])->assertNotFound();
        $this->delete($this->appUrl("/service-categories/{$theirCategory->id}"))->assertNotFound();
        $this->post($this->appUrl('/services/bulk'), ['action' => 'delete', 'ids' => [$theirService->id]])->assertRedirect();

        $this->get($this->appUrl('/resources'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('resources', 0));
        $this->get($this->appUrl('/services?search=Their'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('services.meta.total', 0));

        $this->assertSame('Their Stylist', BookingResource::withoutTenantScope()->findOrFail($theirResource->id)->name);
        $this->assertNotSoftDeleted('booking_resources', ['id' => $theirResource->id]);
        $this->assertNotSoftDeleted('services', ['id' => $theirService->id]);
        $this->assertSame('Their Service', Service::withoutTenantScope()->findOrFail($theirService->id)->name);
        $this->assertDatabaseHas('resource_time_off', ['id' => $theirTimeOff->id]);
        $this->assertDatabaseHas('service_categories', ['id' => $theirCategory->id, 'name' => $theirCategory->name]);
    }

    public function test_composite_foreign_keys_reject_cross_tenant_references(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $appointment = $this->book($a, $this->makeResource($a), '2026-10-06 11:00');
        $theirResource = $this->makeResource($b);
        $theirCustomer = $this->makeCustomer($b);
        $theirService = $this->makeService($b);

        foreach (['booking_resource_id' => $theirResource->id, 'customer_id' => $theirCustomer->id, 'service_id' => $theirService->id] as $column => $id) {
            try {
                DB::transaction(fn () => DB::table('appointments')->where('id', $appointment->id)->update([$column => $id]));
                $this->fail("A cross-tenant {$column} was accepted.");
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->errorInfo[0]);
            }
        }

        $this->expectException(QueryException::class);
        DB::table('booking_resource_service')->insert(['tenant_id' => $a->id, 'booking_resource_id' => $appointment->booking_resource_id, 'service_id' => $theirService->id]);
    }

    public function test_booking_models_fail_closed_without_a_tenant(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $this->makeService($tenant, [], [$resource]);
        $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, BookingResource::query()->count());
        $this->assertSame(0, Service::query()->count());
        $this->assertSame(0, ServiceCategory::query()->count());

        $this->expectException(MissingTenantContext::class);
        Service::query()->create(['name' => 'Orphan', 'duration_minutes' => 30, 'price' => 0]);
    }

    public function test_writing_a_booking_into_another_tenant_is_refused(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        $this->expectException(CrossTenantWrite::class);
        $this->inTenant($a, fn () => BookingResource::query()->create(['tenant_id' => $b->id, 'name' => 'Sneaky']));
    }
}
