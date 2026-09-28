<?php

namespace Tests\Feature\Booking;

use App\Domain\Activity\Models\Activity;
use App\Domain\Booking\Actions\AddTimeOff;
use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Actions\ChangeAppointmentStatus;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Events\AppointmentConfirmed;
use App\Domain\Booking\Events\AppointmentCreated;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Customer\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AppointmentBookingTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_a_booking_takes_the_service_duration_and_price_and_is_confirmed(): void
    {
        Event::fake([AppointmentCreated::class, AppointmentConfirmed::class]);
        $tenant = $this->createTenant();
        $sana = $this->makeResource($tenant, ['name' => 'Sana']);
        $haircut = $this->makeService($tenant, ['name' => 'Haircut', 'duration_minutes' => 45, 'price' => 600], [$sana]);
        $customer = $this->makeCustomer($tenant);

        $appointment = $this->book($tenant, $sana, '2026-10-06 11:00', ['service_id' => $haircut->id, 'customer_id' => $customer->id], $this->ownerOf($tenant));

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame('2026-10-06T05:30:00+00:00', $appointment->starts_at->utc()->toIso8601String());
        $this->assertSame(45, $appointment->durationMinutes());
        $this->assertSame('600.00', $appointment->price);
        $this->assertNotNull($appointment->confirmed_at);
        $this->assertSame($this->ownerOf($tenant)->id, $appointment->created_by_user_id);

        $activity = $this->inTenant($tenant, fn () => Activity::query()->where('appointment_id', $appointment->id)->sole());
        $this->assertSame('appointment_booked', $activity->type);
        $this->assertSame($customer->id, $activity->customer_id);
        $this->assertSame('Haircut', $activity->metadata['service']);
        $this->assertSame('Sana', $activity->metadata['resource']);

        Event::assertDispatched(AppointmentCreated::class, fn (AppointmentCreated $event) => $event->appointment->is($appointment));
        Event::assertDispatched(AppointmentConfirmed::class, fn (AppointmentConfirmed $event) => $event->appointment->is($appointment));
    }

    public function test_bookings_start_pending_when_auto_confirm_is_off(): void
    {
        Event::fake([AppointmentCreated::class, AppointmentConfirmed::class]);
        $tenant = $this->createTenant();
        $this->inTenant($tenant, fn () => app(BookingSettings::class)->update(['auto_confirm' => false]));
        $resource = $this->makeResource($tenant);

        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $this->assertNull($appointment->confirmed_at);
        Event::assertDispatched(AppointmentCreated::class);
        Event::assertNotDispatched(AppointmentConfirmed::class);

        // Staff can still book a confirmed appointment explicitly.
        $this->assertSame(AppointmentStatus::Confirmed, $this->book($tenant, $resource, '2026-10-06 13:00', ['status' => 'confirmed'])->status);
    }

    public function test_a_new_appointment_must_be_pending_or_confirmed(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);

        $this->assertBookingRejected('status', fn () => $this->book($tenant, $resource, '2026-10-06 11:00', ['status' => 'completed']));
    }

    public function test_an_inline_customer_is_created_once_and_reused_by_phone(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);

        $first = $this->book($tenant, $resource, '2026-10-06 11:00', ['customer' => ['name' => 'Neha Kulkarni', 'phone' => '9876543210']]);
        $second = $this->book($tenant, $resource, '2026-10-06 13:00', ['customer' => ['name' => 'Neha K', 'phone' => '+91 98765 43210']]);

        $this->assertSame($first->customer_id, $second->customer_id);
        $this->inTenant($tenant, function () use ($first) {
            $this->assertSame(1, Customer::query()->where('name', 'like', 'Neha%')->count());
            $created = Activity::query()->where('customer_id', $first->customer_id)->where('type', 'created')->sole();
            $this->assertSame('booking', $created->metadata['via']);
        });
    }

    public function test_a_booking_needs_a_customer(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);

        $this->assertBookingRejected('customer_id', fn () => $this->book($tenant, $resource, '2026-10-06 11:00', ['customer' => ['name' => '']]));
    }

    public function test_unavailable_times_are_rejected(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, [], $this->hours('09:00', '18:00', [1, 2, 3, 4, 5, 6]));
        $this->inTenant($tenant, fn () => app(AddTimeOff::class)->handle($resource, $this->local($tenant, '2026-10-06 14:00'), $this->local($tenant, '2026-10-06 16:00'), 'Training'));

        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-06 08:00'), 'outside');
        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-06 17:30'), 'outside');
        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-11 11:00'), 'outside'); // Sunday: closed
        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-06 15:00'), 'time off');
        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-05 09:00'), 'future');

        $this->inTenant($tenant, fn () => $resource->update(['is_active' => false]));
        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-06 11:00'), 'not taking bookings');

        $this->assertSame(0, $this->inTenant($tenant, fn () => Appointment::query()->count()));
    }

    public function test_staff_can_book_outside_working_hours_when_they_choose_to(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);

        $appointment = $this->book($tenant, $resource, '2026-10-06 19:00', ['allow_outside_hours' => true]);

        $this->assertSame('2026-10-06T13:30:00+00:00', $appointment->starts_at->utc()->toIso8601String());
    }

    public function test_a_taken_slot_cannot_be_booked_twice(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, ['name' => 'Sana']);
        $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-06 11:00'), 'already booked for Sana');
        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-06 11:30'), 'already booked');
        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-06 10:30'), 'already booked');
        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-06 10:00', ['duration_minutes' => 180]), 'already booked');

        // Back-to-back appointments touch but do not overlap.
        $this->book($tenant, $resource, '2026-10-06 10:00');
        $this->book($tenant, $resource, '2026-10-06 12:00');

        $this->assertSame(3, $this->inTenant($tenant, fn () => Appointment::query()->count()));
    }

    public function test_different_resources_can_be_booked_at_the_same_time(): void
    {
        $tenant = $this->createTenant();
        $sana = $this->makeResource($tenant);
        $riya = $this->makeResource($tenant);

        $this->book($tenant, $sana, '2026-10-06 11:00');
        $this->book($tenant, $riya, '2026-10-06 11:00');

        $this->assertSame(2, $this->inTenant($tenant, fn () => Appointment::query()->count()));
    }

    public function test_a_cancelled_appointment_frees_its_time(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $first = $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->inTenant($tenant, fn () => app(ChangeAppointmentStatus::class)->handle($first, AppointmentStatus::Cancelled));
        $second = $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->assertSame(AppointmentStatus::Confirmed, $second->status);
    }

    public function test_the_database_rejects_overlapping_appointments_on_a_resource(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $existing = $this->book($tenant, $resource, '2026-10-06 11:00');

        $row = fn (string $status) => [
            'tenant_id' => $tenant->id,
            'customer_id' => $existing->customer_id,
            'booking_resource_id' => $resource->id,
            'starts_at' => $this->local($tenant, '2026-10-06 11:30'),
            'ends_at' => $this->local($tenant, '2026-10-06 12:30'),
            'status' => $status,
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::transaction(fn () => DB::table('appointments')->insert($row('confirmed')));
            $this->fail('The overlapping row was accepted.');
        } catch (QueryException $exception) {
            $this->assertTrue(BookAppointment::isOverlap($exception));
        }

        try {
            DB::transaction(fn () => DB::table('appointments')->insert($row('pending')));
            $this->fail('The overlapping pending row was accepted.');
        } catch (QueryException $exception) {
            $this->assertTrue(BookAppointment::isOverlap($exception));
        }

        // Cancelled and no-show rows do not hold the time.
        DB::table('appointments')->insert($row('cancelled'));
        DB::table('appointments')->insert($row('no_show'));

        $this->assertSame(3, DB::table('appointments')->where('booking_resource_id', $resource->id)->count());
    }

    public function test_a_concurrent_booking_that_passed_the_checks_is_stopped_by_the_constraint(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $rival = $this->makeCustomer($tenant);
        $fired = false;

        // Another request commits the same slot between our availability check and our insert.
        Appointment::creating(function (Appointment $appointment) use (&$fired, $rival, $tenant) {
            if ($fired) {
                return;
            }

            $fired = true;
            DB::table('appointments')->insert([
                'tenant_id' => $tenant->id,
                'customer_id' => $rival->id,
                'booking_resource_id' => $appointment->booking_resource_id,
                'starts_at' => $appointment->starts_at,
                'ends_at' => $appointment->ends_at,
                'status' => 'confirmed',
                'source' => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertBookingRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-06 11:00'), 'just booked');
        $this->assertTrue($fired);
        $this->assertSame(0, $this->inTenant($tenant, fn () => Activity::query()->where('type', 'appointment_booked')->count()));
    }

    public function test_the_service_must_be_active_and_offered_by_the_resource(): void
    {
        $tenant = $this->createTenant();
        $sana = $this->makeResource($tenant, ['name' => 'Sana']);
        $riya = $this->makeResource($tenant);
        $facial = $this->makeService($tenant, ['name' => 'Facial'], [$riya]);
        $retired = $this->makeService($tenant, ['is_active' => false], [$sana]);

        $this->assertBookingRejected('service_id', fn () => $this->book($tenant, $sana, '2026-10-06 11:00', ['service_id' => $facial->id]), 'Sana does not offer Facial');
        $this->assertBookingRejected('service_id', fn () => $this->book($tenant, $sana, '2026-10-06 11:00', ['service_id' => $retired->id]));
    }

    public function test_the_duration_must_be_within_bounds(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);

        $this->assertBookingRejected('duration_minutes', fn () => $this->book($tenant, $resource, '2026-10-06 11:00', ['duration_minutes' => 2]));
        $this->assertBookingRejected('duration_minutes', fn () => $this->book($tenant, $resource, '2026-10-06 11:00', ['duration_minutes' => 721]));
    }

    public function test_seconds_are_dropped_from_the_start_time(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);

        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00:42');

        $this->assertSame('2026-10-06T05:30:00+00:00', $appointment->starts_at->utc()->toIso8601String());
    }

    private function assertBookingRejected(string $field, callable $callback, ?string $messageContains = null): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $this->assertArrayHasKey($field, $errors, 'Errors: '.json_encode($errors));

            if ($messageContains) {
                $this->assertStringContainsString($messageContains, $errors[$field][0]);
            }

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}
