<?php

namespace Tests\Feature\Booking;

use App\Domain\Activity\Models\Activity;
use App\Domain\Booking\Actions\ChangeAppointmentStatus;
use App\Domain\Booking\Actions\RescheduleAppointment;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Events\AppointmentCancelled;
use App\Domain\Booking\Events\AppointmentCompleted;
use App\Domain\Booking\Events\AppointmentConfirmed;
use App\Domain\Booking\Events\AppointmentNoShow;
use App\Domain\Booking\Events\AppointmentRescheduled;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Support\BookingSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AppointmentLifecycleTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_an_appointment_can_be_rescheduled(): void
    {
        Event::fake([AppointmentRescheduled::class]);
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, ['name' => 'Sana']);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->reschedule($tenant, $appointment, '2026-10-07 15:00');

        $fresh = $this->inTenant($tenant, fn () => $appointment->fresh());
        $this->assertSame('2026-10-07T09:30:00+00:00', $fresh->starts_at->utc()->toIso8601String());
        $this->assertSame(60, $fresh->durationMinutes());

        $activity = $this->inTenant($tenant, fn () => Activity::query()->where('appointment_id', $appointment->id)->where('type', 'appointment_rescheduled')->sole());
        $this->assertSame('2026-10-06T05:30:00+00:00', $activity->metadata['from']['starts_at']);
        $this->assertSame('2026-10-07T09:30:00+00:00', $activity->metadata['to']['starts_at']);

        Event::assertDispatched(AppointmentRescheduled::class, fn (AppointmentRescheduled $event) => $event->appointment->is($appointment)
            && $event->previousStartsAt->equalTo($this->local($tenant, '2026-10-06 11:00'))
            && $event->previousResourceId === null);
    }

    public function test_an_appointment_can_be_shifted_into_its_own_time(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->reschedule($tenant, $appointment, '2026-10-06 11:30');

        $this->assertSame('2026-10-06T06:00:00+00:00', $this->inTenant($tenant, fn () => $appointment->fresh())->starts_at->utc()->toIso8601String());
    }

    public function test_rescheduling_into_a_taken_or_closed_time_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');
        $this->book($tenant, $resource, '2026-10-06 14:00');

        $this->assertRejected('starts_at', fn () => $this->reschedule($tenant, $appointment, '2026-10-06 13:30'));
        $this->assertRejected('starts_at', fn () => $this->reschedule($tenant, $appointment, '2026-10-06 20:00'));
        $this->assertRejected('starts_at', fn () => $this->reschedule($tenant, $appointment, '2026-10-05 08:00'));

        $this->assertSame('2026-10-06T05:30:00+00:00', $this->inTenant($tenant, fn () => $appointment->fresh())->starts_at->utc()->toIso8601String());
    }

    public function test_an_appointment_can_move_to_another_resource_that_offers_the_service(): void
    {
        Event::fake([AppointmentRescheduled::class]);
        $tenant = $this->createTenant();
        $sana = $this->makeResource($tenant);
        $riya = $this->makeResource($tenant);
        $meera = $this->makeResource($tenant, ['name' => 'Meera']);
        $haircut = $this->makeService($tenant, ['name' => 'Haircut'], [$sana, $riya]);
        $appointment = $this->book($tenant, $sana, '2026-10-06 11:00', ['service_id' => $haircut->id]);

        $this->assertRejected('booking_resource_id', fn () => $this->reschedule($tenant, $appointment, '2026-10-06 11:00', $meera->id));

        $this->reschedule($tenant, $appointment, '2026-10-06 11:00', $riya->id);

        $this->assertSame($riya->id, $this->inTenant($tenant, fn () => $appointment->fresh())->booking_resource_id);
        Event::assertDispatched(AppointmentRescheduled::class, fn (AppointmentRescheduled $event) => $event->previousResourceId === $sana->id);

        // Sana's time is free again.
        $this->book($tenant, $sana, '2026-10-06 11:00');
    }

    public function test_only_active_appointments_can_be_rescheduled(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');
        $this->changeStatus($tenant, $appointment, AppointmentStatus::Cancelled);

        $this->assertRejected('starts_at', fn () => $this->reschedule($tenant, $appointment, '2026-10-06 15:00'));
    }

    public function test_cancelling_records_the_reason_and_fires_the_trigger(): void
    {
        Event::fake([AppointmentCancelled::class]);
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->changeStatus($tenant, $appointment, AppointmentStatus::Cancelled, 'Customer is travelling');

        $fresh = $this->inTenant($tenant, fn () => $appointment->fresh());
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame('Customer is travelling', $fresh->cancellation_reason);

        $activity = $this->inTenant($tenant, fn () => Activity::query()->where('appointment_id', $appointment->id)->where('type', 'appointment_cancelled')->sole());
        $this->assertSame('Customer is travelling', $activity->body);
        $this->assertSame($appointment->customer_id, $activity->customer_id);
        Event::assertDispatched(AppointmentCancelled::class);
    }

    public function test_pending_appointments_can_be_confirmed(): void
    {
        Event::fake([AppointmentConfirmed::class]);
        $tenant = $this->createTenant();
        $this->inTenant($tenant, fn () => app(BookingSettings::class)->update(['auto_confirm' => false]));
        $resource = $this->makeResource($tenant);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->changeStatus($tenant, $appointment, AppointmentStatus::Confirmed);

        $fresh = $this->inTenant($tenant, fn () => $appointment->fresh());
        $this->assertSame(AppointmentStatus::Confirmed, $fresh->status);
        $this->assertNotNull($fresh->confirmed_at);
        Event::assertDispatched(AppointmentConfirmed::class);
    }

    public function test_completed_and_no_show_need_the_appointment_to_have_started(): void
    {
        Event::fake([AppointmentCompleted::class, AppointmentNoShow::class]);
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $visit = $this->book($tenant, $resource, '2026-10-06 11:00');
        $missed = $this->book($tenant, $resource, '2026-10-06 13:00');

        $this->assertRejected('status', fn () => $this->changeStatus($tenant, $visit, AppointmentStatus::Completed));
        $this->assertRejected('status', fn () => $this->changeStatus($tenant, $missed, AppointmentStatus::NoShow));

        $this->travelToBookingDay('2026-10-06 15:00');
        $this->changeStatus($tenant, $visit, AppointmentStatus::Completed);
        $this->changeStatus($tenant, $missed, AppointmentStatus::NoShow);

        $this->inTenant($tenant, function () use ($visit, $missed) {
            $this->assertSame(AppointmentStatus::Completed, $visit->fresh()->status);
            $this->assertNotNull($visit->fresh()->completed_at);
            $this->assertSame(AppointmentStatus::NoShow, $missed->fresh()->status);
            $this->assertNotNull($missed->fresh()->no_show_at);
        });
        Event::assertDispatched(AppointmentCompleted::class);
        Event::assertDispatched(AppointmentNoShow::class);
    }

    public function test_finished_appointments_cannot_change_status_again(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $cancelled = $this->book($tenant, $resource, '2026-10-06 11:00');
        $completed = $this->book($tenant, $resource, '2026-10-06 13:00');
        $this->changeStatus($tenant, $cancelled, AppointmentStatus::Cancelled);
        $this->travelToBookingDay('2026-10-06 15:00');
        $this->changeStatus($tenant, $completed, AppointmentStatus::Completed);

        $this->assertRejected('status', fn () => $this->changeStatus($tenant, $cancelled, AppointmentStatus::Confirmed));
        $this->assertRejected('status', fn () => $this->changeStatus($tenant, $completed, AppointmentStatus::Cancelled));
        $this->assertRejected('status', fn () => $this->changeStatus($tenant, $completed, AppointmentStatus::NoShow));
    }

    public function test_status_and_reschedule_over_http(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl("/appointments/{$appointment->id}"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/appointments/Show')
                ->where('appointment.status', 'confirmed')
                ->where('transitions.0.value', 'completed')
                ->where('transitions.0.available', false)
                ->has('activities', 1));

        $this->patch($this->appUrl("/appointments/{$appointment->id}/reschedule"), ['starts_at' => '2026-10-06T16:00'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertSame('2026-10-06T10:30:00+00:00', $this->inTenant($tenant, fn () => $appointment->fresh())->starts_at->utc()->toIso8601String());

        $this->put($this->appUrl("/appointments/{$appointment->id}"), ['price' => 750, 'notes' => 'Prefers a quiet chair'])->assertSessionHasNoErrors();
        $this->patch($this->appUrl("/appointments/{$appointment->id}/status"), ['status' => 'cancelled', 'reason' => 'Unwell'])->assertSessionHasNoErrors();

        $fresh = $this->inTenant($tenant, fn () => $appointment->fresh());
        $this->assertSame('750.00', $fresh->price);
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->status);
        $this->assertSame(
            ['appointment_booked', 'appointment_cancelled', 'appointment_rescheduled', 'appointment_updated'],
            $this->inTenant($tenant, fn () => Activity::query()->where('appointment_id', $appointment->id)->orderBy('type')->pluck('type')->all()),
        );
    }

    public function test_cancelling_needs_the_cancel_permission(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->actingAs($staff);

        $this->patch($this->appUrl("/appointments/{$appointment->id}/status"), ['status' => 'cancelled'])->assertForbidden();
        $this->post($this->appUrl('/appointments/bulk'), ['action' => 'cancelled', 'ids' => [$appointment->id]])->assertForbidden();

        $this->travelToBookingDay('2026-10-06 12:30');
        $this->patch($this->appUrl("/appointments/{$appointment->id}/status"), ['status' => 'completed'])->assertSessionHasNoErrors();

        $this->assertSame(AppointmentStatus::Completed, $this->inTenant($tenant, fn () => $appointment->fresh())->status);
    }

    public function test_bulk_status_changes_skip_ineligible_appointments(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $past = $this->book($tenant, $resource, '2026-10-05 11:00');
        $future = $this->book($tenant, $resource, '2026-10-06 11:00');
        $this->travelToBookingDay('2026-10-05 13:00');

        $this->actingAs($this->ownerOf($tenant))
            ->post($this->appUrl('/appointments/bulk'), ['action' => 'completed', 'ids' => [$past->id, $future->id]])
            ->assertRedirect()
            ->assertSessionHas('success', '1 appointment updated. 1 skipped (not eligible).');

        $this->inTenant($tenant, function () use ($past, $future) {
            $this->assertSame(AppointmentStatus::Completed, $past->fresh()->status);
            $this->assertSame(AppointmentStatus::Confirmed, $future->fresh()->status);
        });
    }

    private function reschedule($tenant, Appointment $appointment, string $localStart, ?int $resourceId = null): Appointment
    {
        return $this->inTenant($tenant, fn () => app(RescheduleAppointment::class)->handle(
            Appointment::query()->findOrFail($appointment->id),
            $this->local($tenant, $localStart),
            $resourceId,
        ));
    }

    private function changeStatus($tenant, Appointment $appointment, AppointmentStatus $status, ?string $reason = null): Appointment
    {
        return $this->inTenant($tenant, fn () => app(ChangeAppointmentStatus::class)->handle(Appointment::query()->findOrFail($appointment->id), $status, null, $reason));
    }

    private function assertRejected(string $field, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), 'Errors: '.json_encode($exception->errors()));

            return;
        }

        $this->fail("Expected a validation error on [{$field}].");
    }
}
