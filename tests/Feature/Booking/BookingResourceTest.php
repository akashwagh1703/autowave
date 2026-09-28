<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Actions\AddTimeOff;
use App\Domain\Booking\Actions\ChangeAppointmentStatus;
use App\Domain\Booking\Actions\DeleteBookingResource;
use App\Domain\Booking\Actions\SaveBookingResource;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Models\WorkingHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingResourceTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_a_new_resource_starts_with_the_default_hours(): void
    {
        $salon = $this->createTenant();
        $turf = $this->createTenant('Green Turf', 'turf');

        $stylist = $this->inTenant($salon, fn () => app(SaveBookingResource::class)->handle(['name' => 'Sana']));
        $pitch = $this->inTenant($turf, fn () => app(SaveBookingResource::class)->handle(['name' => 'Turf A']));

        $this->inTenant($salon, function () use ($stylist) {
            $hours = $stylist->workingHours()->get();
            $this->assertSame([1, 2, 3, 4, 5, 6], $hours->pluck('weekday')->all());
            $this->assertSame(['10:00', '20:00'], [$hours[0]->startTime(), $hours[0]->endTime()]);
        });
        $this->inTenant($turf, function () use ($pitch) {
            $hours = $pitch->workingHours()->get();
            $this->assertCount(7, $hours);
            $this->assertSame(['06:00', '23:00'], [$hours[0]->startTime(), $hours[0]->endTime()]);
        });
    }

    public function test_working_hours_are_validated_and_replaced(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);

        $this->assertRejected('working_hours.0', fn () => $this->save($tenant, $resource, [['weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '09:00']]));
        $this->assertRejected('working_hours.0', fn () => $this->save($tenant, $resource, [['weekday' => 8, 'starts_at' => '09:00', 'ends_at' => '18:00']]));
        $this->assertRejected('working_hours', fn () => $this->save($tenant, $resource, [
            ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '13:00'],
            ['weekday' => 1, 'starts_at' => '12:00', 'ends_at' => '18:00'],
        ]));
        $this->assertRejected('working_hours', fn () => $this->save($tenant, $resource, array_map(
            fn (int $hour) => ['weekday' => 1, 'starts_at' => sprintf('%02d:00', $hour), 'ends_at' => sprintf('%02d:30', $hour)],
            range(8, 12),
        )));

        $this->save($tenant, $resource, [
            ['weekday' => 3, 'starts_at' => '14:00', 'ends_at' => '18:00'],
            ['weekday' => 3, 'starts_at' => '09:00', 'ends_at' => '13:00'],
        ]);

        $this->inTenant($tenant, fn () => $this->assertSame(
            [[3, '09:00', '13:00'], [3, '14:00', '18:00']],
            $resource->workingHours()->get()->map(fn (WorkingHour $hour) => [$hour->weekday, $hour->startTime(), $hour->endTime()])->all(),
        ));
    }

    public function test_changing_hours_keeps_existing_appointments(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->save($tenant, $resource, $this->hours('14:00', '18:00'));

        $this->inTenant($tenant, fn () => $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status));
    }

    public function test_a_team_member_can_have_only_one_calendar(): void
    {
        $tenant = $this->createTenant();
        $other = $this->createTenant('Other Salon');
        $stylist = User::factory()->create();
        $membership = $this->addMember($tenant, $stylist, 'staff');
        $foreign = $this->membershipOf($other, $this->ownerOf($other));

        $resource = $this->makeResource($tenant, ['name' => 'Sana', 'tenant_user_id' => $membership->id]);
        $this->assertSame($membership->id, $resource->tenant_user_id);

        $this->assertRejected('tenant_user_id', fn () => $this->makeResource($tenant, ['tenant_user_id' => $membership->id]));
        $this->assertRejected('tenant_user_id', fn () => $this->makeResource($tenant, ['tenant_user_id' => $foreign->id]));

        // Saving the same resource again keeps its link.
        $this->inTenant($tenant, fn () => app(SaveBookingResource::class)->handle(['name' => 'Sana K', 'tenant_user_id' => $membership->id], $resource));
        $this->assertSame('Sana K', $this->inTenant($tenant, fn () => $resource->fresh())->name);
    }

    public function test_a_resource_with_upcoming_appointments_cannot_be_deleted(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, ['name' => 'Sana']);
        $past = $this->book($tenant, $resource, '2026-10-05 11:00');
        $upcoming = $this->book($tenant, $resource, '2026-10-06 11:00');
        $this->travelToBookingDay('2026-10-05 13:00');

        $this->assertRejected('resource', fn () => $this->inTenant($tenant, fn () => app(DeleteBookingResource::class)->handle($resource)));

        $this->inTenant($tenant, fn () => app(ChangeAppointmentStatus::class)->handle($upcoming, AppointmentStatus::Cancelled));
        $this->inTenant($tenant, fn () => app(DeleteBookingResource::class)->handle($resource));

        $this->assertSoftDeleted('booking_resources', ['id' => $resource->id]);
        $this->inTenant($tenant, fn () => $this->assertSame('Sana', Appointment::query()->findOrFail($past->id)->resource->name));
    }

    public function test_time_off_blocks_bookings_and_cannot_cover_active_appointments(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $this->book($tenant, $resource, '2026-10-06 11:00');

        $this->assertRejected('starts_at', fn () => $this->addTimeOff($tenant, $resource, '2026-10-06 10:00', '2026-10-06 18:00'));

        $this->addTimeOff($tenant, $resource, '2026-10-07 00:00', '2026-10-08 00:00');
        $this->assertRejected('starts_at', fn () => $this->book($tenant, $resource, '2026-10-07 11:00'));
    }

    public function test_resources_can_be_managed_over_http(): void
    {
        $tenant = $this->createTenant();
        $service = $this->makeService($tenant);
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/resources'), [
            'name' => '  Chair   3 ',
            'color' => '#16a34a',
            'is_active' => true,
            'service_ids' => [$service->id],
            'working_hours' => $this->hours('10:00', '19:00', [1, 2, 3]),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $resource = $this->inTenant($tenant, fn () => BookingResource::query()->where('name', 'Chair 3')->sole());
        $this->inTenant($tenant, function () use ($resource, $service) {
            $this->assertTrue($resource->offers($service));
            $this->assertSame(3, $resource->workingHours()->count());
        });

        $this->post($this->appUrl("/resources/{$resource->id}/time-off"), [
            'starts_at' => '2026-10-12T00:00',
            'ends_at' => '2026-10-13T00:00',
            'reason' => 'Holiday',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'booking_resource.time_off_added']);

        $this->get($this->appUrl("/resources/{$resource->id}"))->assertOk();
        $this->delete($this->appUrl("/resources/{$resource->id}"))->assertRedirect($this->appUrl('/resources'));
        $this->assertSoftDeleted('booking_resources', ['id' => $resource->id]);
    }

    private function save($tenant, BookingResource $resource, array $hours): BookingResource
    {
        return $this->inTenant($tenant, fn () => app(SaveBookingResource::class)->handle(['name' => $resource->name, 'working_hours' => $hours], $resource));
    }

    private function addTimeOff($tenant, BookingResource $resource, string $from, string $to): void
    {
        $this->inTenant($tenant, fn () => app(AddTimeOff::class)->handle($resource, $this->local($tenant, $from), $this->local($tenant, $to)));
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
