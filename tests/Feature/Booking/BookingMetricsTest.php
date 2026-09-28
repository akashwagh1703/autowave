<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Actions\ChangeAppointmentStatus;
use App\Domain\Booking\Actions\SaveBookingResource;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingMetricsTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_salon_widgets_show_live_booking_and_revenue_figures(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $service = $this->makeService($tenant, ['price' => 500], [$resource]);
        $regular = $this->makeCustomer($tenant);
        $first = $this->book($tenant, $resource, '2026-10-05 11:00', ['service_id' => $service->id, 'customer_id' => $regular->id]);
        $second = $this->book($tenant, $resource, '2026-10-05 12:00', ['service_id' => $service->id, 'customer_id' => $regular->id]);
        $cancelled = $this->book($tenant, $resource, '2026-10-05 15:00', ['service_id' => $service->id]);
        $this->book($tenant, $resource, '2026-10-06 11:00', ['service_id' => $service->id]);

        // Another tenant's figures never leak in.
        $other = $this->createTenant('Other Salon');
        $this->book($other, $this->makeResource($other), '2026-10-05 11:00');

        $this->travelToBookingDay('2026-10-05 14:00');
        $this->changeStatus($tenant, $first, AppointmentStatus::Completed);
        $this->changeStatus($tenant, $second, AppointmentStatus::Completed);
        $this->changeStatus($tenant, $cancelled, AppointmentStatus::Cancelled);

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.appointments_today.value', 2)
                ->where('metrics.revenue_today.value', '1000.00')
                ->where('metrics.revenue_today.type', 'currency')
                ->where('metrics.service_sales.value', '1000.00')
                ->where('metrics.repeat_customers.value', 1));

        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');
        $this->actingAs($receptionist)
            ->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.appointments_today.value', 2)
                ->missing('metrics.revenue_today')
                ->missing('metrics.service_sales'));
    }

    public function test_turf_widgets_count_bookings_free_slots_and_cancellations(): void
    {
        $tenant = $this->createTenant('Green Turf', 'turf');
        $pitch = $this->inTenant($tenant, fn () => app(SaveBookingResource::class)->handle(['name' => 'Turf A']));
        $this->book($tenant, $pitch, '2026-10-05 12:00');
        $this->changeStatus($tenant, $this->book($tenant, $pitch, '2026-10-05 15:00'), AppointmentStatus::Cancelled);

        // 10:00 now; hourly slots 10:00–22:00 are 13, one is booked.
        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.bookings_today.value', 1)
                ->where('metrics.available_slots.value', 12)
                ->where('metrics.cancellations.value', 1)
                ->where('metrics.revenue_today.value', '0'));
    }

    public function test_clinic_widgets_count_no_shows(): void
    {
        $tenant = $this->createTenant('City Clinic', 'clinic');
        $doctor = $this->makeResource($tenant);
        $missed = $this->book($tenant, $doctor, '2026-10-05 11:00');
        $this->travelToBookingDay('2026-10-05 12:30');
        $this->changeStatus($tenant, $missed, AppointmentStatus::NoShow);

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('metrics.no_shows.value', 1)->where('metrics.appointments_today.value', 0));
    }

    public function test_booking_widgets_are_hidden_without_appointment_access(): void
    {
        $tenant = $this->createTenant();
        $accountant = User::factory()->create();
        $this->addMember($tenant, $accountant, 'accountant');

        $this->actingAs($accountant)
            ->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page->missing('metrics.appointments_today')->missing('metrics.revenue_today'));
    }

    private function changeStatus(Tenant $tenant, Appointment $appointment, AppointmentStatus $status): void
    {
        $this->inTenant($tenant, fn () => app(ChangeAppointmentStatus::class)->handle(Appointment::query()->findOrFail($appointment->id), $status));
    }
}
