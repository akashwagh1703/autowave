<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Actions\AddTimeOff;
use App\Domain\Booking\Actions\ChangeAppointmentStatus;
use App\Domain\Booking\Actions\SaveBookingResource;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Services\Availability;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_slots_step_through_working_hours_by_the_slot_interval(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, [], $this->hours('09:00', '11:00'));

        $this->assertSame(['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30'], $this->times($tenant, $resource, '2026-10-06', 30));
        $this->assertSame(['09:00'], $this->times($tenant, $resource, '2026-10-06', 120));
        $this->assertSame([], $this->times($tenant, $resource, '2026-10-06', 150));

        $this->inTenant($tenant, fn () => app(BookingSettings::class)->update(['slot_interval' => 30]));
        $this->assertSame(['09:00', '09:30', '10:00', '10:30'], $this->times($tenant, $resource, '2026-10-06', 30));
    }

    public function test_split_shifts_offer_slots_in_each_window(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, [], [
            ['weekday' => 2, 'starts_at' => '15:00', 'ends_at' => '16:00'],
            ['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '10:00'],
        ]);

        $this->assertSame(['09:00', '15:00'], $this->times($tenant, $resource, '2026-10-06', 60));
        $this->assertSame([], $this->times($tenant, $resource, '2026-10-07', 60));
    }

    public function test_booked_times_and_time_off_are_not_offered(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, [], $this->hours('09:00', '12:00'));
        $booked = $this->book($tenant, $resource, '2026-10-06 09:30', ['duration_minutes' => 30]);
        $this->inTenant($tenant, fn () => app(AddTimeOff::class)->handle($resource, $this->local($tenant, '2026-10-06 11:00'), $this->local($tenant, '2026-10-06 12:00')));

        $this->assertSame(['09:00', '10:00', '10:15', '10:30'], $this->times($tenant, $resource, '2026-10-06', 30));

        // Rescheduling may reuse the appointment's own time.
        $this->assertSame(
            ['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30'],
            $this->times($tenant, $resource, '2026-10-06', 30, $booked->id),
        );

        // A cancelled appointment releases its time.
        $this->inTenant($tenant, fn () => app(ChangeAppointmentStatus::class)->handle($booked, AppointmentStatus::Cancelled));
        $this->assertContains('09:30', $this->times($tenant, $resource, '2026-10-06', 30));
    }

    public function test_past_times_today_are_not_offered(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, [], $this->hours('09:00', '11:00'));

        // It is 10:00 in India.
        $this->assertSame(['10:00', '10:15', '10:30'], $this->times($tenant, $resource, '2026-10-05', 30));
        $this->assertSame([], $this->times($tenant, $resource, '2026-10-04', 30));
    }

    public function test_inactive_resources_have_no_slots(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, ['is_active' => false]);

        $this->assertSame([], $this->times($tenant, $resource, '2026-10-06', 30));
    }

    public function test_a_turf_offers_hourly_slots_from_its_business_type_settings(): void
    {
        $tenant = $this->createTenant('Green Turf', 'turf');
        $resource = $this->inTenant($tenant, fn () => app(SaveBookingResource::class)->handle(['name' => 'Turf A']));

        $times = $this->times($tenant, $resource, '2026-10-06', 60);

        $this->assertSame('06:00', $times[0]);
        $this->assertSame('22:00', end($times));
        $this->assertCount(17, $times);
    }

    public function test_working_hours_follow_the_tenant_timezone(): void
    {
        $india = $this->createTenant('Pune Salon');
        $newYork = $this->createTenant('NY Salon', 'beauty_salon', null, ['timezone' => 'America/New_York']);
        $inPune = $this->makeResource($india, [], $this->hours('09:00', '10:00'));
        $inNewYork = $this->makeResource($newYork, [], $this->hours('09:00', '10:00'));

        $this->assertSame('2026-10-06T03:30:00+00:00', $this->slots($india, $inPune, '2026-10-06', 60)[0]['starts_at']);
        // New York is on daylight time (UTC−4) in October and standard time (UTC−5) after 1 November.
        $this->assertSame('2026-10-06T13:00:00+00:00', $this->slots($newYork, $inNewYork, '2026-10-06', 60)[0]['starts_at']);
        $this->assertSame('2026-11-02T14:00:00+00:00', $this->slots($newYork, $inNewYork, '2026-11-02', 60)[0]['starts_at']);
        $this->assertSame('09:00', $this->slots($newYork, $inNewYork, '2026-11-02', 60)[0]['time']);
    }

    public function test_bookings_are_interpreted_in_the_tenant_timezone(): void
    {
        $tenant = $this->createTenant('NY Salon', 'beauty_salon', null, ['timezone' => 'America/New_York']);
        $resource = $this->makeResource($tenant, [], $this->hours('09:00', '18:00'));
        $customer = $this->makeCustomer($tenant);
        $this->actingAs($this->ownerOf($tenant));

        // 08:30 in New York: outside hours, even though 08:30 UTC or IST would not be.
        $this->post($this->appUrl('/appointments'), [
            'customer_id' => $customer->id,
            'booking_resource_id' => $resource->id,
            'starts_at' => '2026-10-06T08:30',
            'duration_minutes' => 60,
        ])->assertSessionHasErrors('starts_at');

        $this->post($this->appUrl('/appointments'), [
            'customer_id' => $customer->id,
            'booking_resource_id' => $resource->id,
            'starts_at' => '2026-10-06T09:00',
            'duration_minutes' => 60,
        ])->assertSessionHasNoErrors();

        $appointment = $this->inTenant($tenant, fn () => $resource->appointments()->sole());
        $this->assertSame('2026-10-06T13:00:00+00:00', $appointment->starts_at->utc()->toIso8601String());
    }

    public function test_the_availability_endpoint_returns_slots_and_windows(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, [], $this->hours('09:00', '10:00'));
        $service = $this->makeService($tenant, ['duration_minutes' => 30], [$resource]);

        $this->actingAs($this->ownerOf($tenant))
            ->getJson($this->appUrl("/appointments/availability?resource={$resource->id}&date=2026-10-06&service={$service->id}"))
            ->assertOk()
            ->assertJsonPath('duration', 30)
            ->assertJsonPath('interval', 15)
            ->assertJsonPath('windows.0.starts_at', '09:00')
            ->assertJsonPath('windows.0.ends_at', '10:00')
            ->assertJsonCount(3, 'slots')
            ->assertJsonPath('slots.0.starts_at', '2026-10-06T03:30:00+00:00');
    }

    /** @return list<array{starts_at: string, ends_at: string, time: string}> */
    private function slots(Tenant $tenant, BookingResource $resource, string $date, int $duration, ?int $ignore = null): array
    {
        return $this->inTenant($tenant, fn () => app(Availability::class)->slots(BookingResource::query()->findOrFail($resource->id), $date, $duration, $ignore));
    }

    /** @return list<string> */
    private function times(Tenant $tenant, BookingResource $resource, string $date, int $duration, ?int $ignore = null): array
    {
        return array_column($this->slots($tenant, $resource, $date, $duration, $ignore), 'time');
    }
}
