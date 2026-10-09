<?php

namespace Tests\Feature\Website;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ManageBookingTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const SALON = 'abc-salon.autowave.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    public function test_a_customer_can_cancel_and_reschedule_via_a_signed_link(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant, ['name' => 'Sana']);
        $service = $this->makeService($tenant, ['name' => 'Haircut', 'duration_minutes' => 60], [$resource]);

        $response = $this->from($this->siteUrl(self::SALON))->post($this->siteUrl(self::SALON, '/booking'), [
            'service_id' => $service->id,
            'starts_at' => $this->local($tenant, '2026-10-06 11:00')->toIso8601String(),
            'name' => 'Meera Joshi',
            'phone' => '99887 76655',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();
        $manageUrl = session('booking_confirmation.manage_url');
        $this->assertNotEmpty($manageUrl);

        $appointment = $this->inTenant($tenant, fn () => Appointment::query()->sole());
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);

        $path = parse_url($manageUrl, PHP_URL_PATH).'?'.parse_url($manageUrl, PHP_URL_QUERY);

        $this->get($this->siteUrl(self::SALON, $path))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('website/ManageBooking')
                ->where('appointment.id', $appointment->id)
                ->where('appointment.can_change', true));

        $this->post($this->siteUrl(self::SALON, '/booking/manage/'.$appointment->id.'/reschedule'), [
            'starts_at' => $this->local($tenant, '2026-10-06 14:00')->toIso8601String(),
            'resource_id' => $resource->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue($this->inTenant($tenant, fn () => $appointment->fresh()->starts_at->equalTo($this->local($tenant, '2026-10-06 14:00'))));

        $this->post($this->siteUrl(self::SALON, '/booking/manage/'.$appointment->id.'/cancel'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(AppointmentStatus::Cancelled, $this->inTenant($tenant, fn () => $appointment->fresh()->status));
    }

    public function test_an_unsigned_manage_link_is_rejected(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $service = $this->makeService($tenant, [], [$resource]);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00', ['service_id' => $service->id]);

        $this->get($this->siteUrl(self::SALON, '/booking/manage/'.$appointment->id))->assertForbidden();
    }
}
