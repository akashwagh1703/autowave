<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Service\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingHttpTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelToBookingDay();
    }

    /** Milestone (master prompt §113): a salon creates a service, staff, customer, lead and appointment. */
    public function test_a_salon_can_create_a_service_staff_customer_lead_and_appointment(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/resources'), [
            'name' => 'Sana',
            'color' => '#db2777',
            'is_active' => true,
            'working_hours' => $this->hours('10:00', '20:00', [1, 2, 3, 4, 5, 6]),
        ])->assertSessionHasNoErrors();
        $sana = $this->inTenant($tenant, fn () => BookingResource::query()->where('name', 'Sana')->sole());

        $this->post($this->appUrl('/services'), [
            'name' => 'Haircut',
            'duration_minutes' => 45,
            'price' => 500,
            'is_active' => true,
            'resource_ids' => [$sana->id],
        ])->assertSessionHasNoErrors();
        $haircut = $this->inTenant($tenant, fn () => Service::query()->where('name', 'Haircut')->sole());

        $this->post($this->appUrl('/customers'), ['name' => 'Priya Shah', 'phone' => '9811122233'])->assertSessionHasNoErrors();
        $this->post($this->appUrl('/leads'), ['name' => 'Rohan Mehta', 'phone' => '9822233344', 'interest' => 'Bridal package'])->assertSessionHasNoErrors();
        $priya = $this->inTenant($tenant, fn () => Customer::query()->where('name', 'Priya Shah')->sole());

        $response = $this->post($this->appUrl('/appointments'), [
            'customer_id' => $priya->id,
            'booking_resource_id' => $sana->id,
            'service_id' => $haircut->id,
            'starts_at' => '2026-10-06T11:00',
        ])->assertSessionHasNoErrors();

        $appointment = $this->inTenant($tenant, fn () => Appointment::query()->sole());
        $response->assertRedirect($this->appUrl("/appointments/{$appointment->id}"));
        $this->assertSame(45, $appointment->durationMinutes());
        $this->assertSame('500.00', $appointment->price);
        $this->assertTrue($this->inTenant($tenant, fn () => Lead::query()->where('name', 'Rohan Mehta')->exists()));

        $this->get($this->appUrl("/customers/{$priya->id}"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('appointments', 1)
                ->where('appointments.0.service.name', 'Haircut')
                ->where('activities.0.type', 'appointment_booked'));

        $this->get($this->appUrl('/appointments?date=2026-10-06'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/appointments/Calendar')
                ->where('appointments.0.id', $appointment->id)
                ->where('summary.booked', 1));
    }

    public function test_a_new_customer_can_be_added_while_booking(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);

        $this->actingAs($this->ownerOf($tenant))
            ->post($this->appUrl('/appointments'), [
                'customer' => ['name' => ' Walk-in   Guest ', 'phone' => '9833344455'],
                'booking_resource_id' => $resource->id,
                'starts_at' => '2026-10-06T12:00',
                'duration_minutes' => 30,
                'notes' => 'First visit',
            ])->assertSessionHasNoErrors();

        $appointment = $this->inTenant($tenant, fn () => Appointment::query()->with('customer')->sole());
        $this->assertSame('Walk-in Guest', $appointment->customer->name);
        $this->assertSame('First visit', $appointment->notes);
    }

    public function test_booking_pages_render(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $service = $this->makeService($tenant, [], [$resource]);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00', ['service_id' => $service->id]);
        $this->actingAs($this->ownerOf($tenant));

        $pages = [
            '/appointments' => 'business/appointments/Calendar',
            '/appointments/list' => 'business/appointments/Index',
            '/appointments/create?resource='.$resource->id.'&date=2026-10-06&time=11:30' => 'business/appointments/Create',
            "/appointments/{$appointment->id}" => 'business/appointments/Show',
            '/resources' => 'business/resources/Index',
            '/resources/create' => 'business/resources/Create',
            "/resources/{$resource->id}" => 'business/resources/Show',
            "/resources/{$resource->id}/edit" => 'business/resources/Edit',
            '/services' => 'business/services/Index',
            '/services/create' => 'business/services/Create',
            "/services/{$service->id}/edit" => 'business/services/Edit',
            '/settings/booking' => 'business/settings/Booking',
        ];

        foreach ($pages as $path => $component) {
            $this->get($this->appUrl($path))->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_the_tenant_share_includes_engines_and_the_resource_label(): void
    {
        $salon = $this->createTenant();
        $turf = $this->createTenant('Green Turf', 'turf');

        $this->actingAs($this->ownerOf($salon))
            ->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.engines', fn ($engines) => in_array('booking', collect($engines)->all(), true))
                ->where('tenant.resource_label.singular', 'Staff')
                ->where('tenant.resource_label.plural', 'Staff'));

        $this->actingAs($this->ownerOf($turf))
            ->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.resource_label.singular', 'Turf')
                ->where('tenant.resource_label.plural', 'Turfs'));

        $coaching = $this->createTenant('Bright Minds', 'coaching');
        $this->actingAs($this->ownerOf($coaching))
            ->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('tenant.resource_label', null));
    }

    public function test_booking_routes_are_hidden_when_the_engine_is_off(): void
    {
        $coaching = $this->createTenant('Bright Minds', 'coaching');
        $this->actingAs($this->ownerOf($coaching));

        foreach (['/appointments', '/appointments/list', '/appointments/create', '/resources', '/services', '/settings/booking'] as $path) {
            $this->get($this->appUrl($path))->assertNotFound();
        }
        $this->post($this->appUrl('/appointments'), [])->assertNotFound();

        $this->get($this->appUrl('/customers/'.$this->makeCustomer($coaching)->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('appointments', null));
    }

    public function test_a_turf_books_without_services(): void
    {
        $turf = $this->createTenant('Green Turf', 'turf');
        $pitch = $this->makeResource($turf, ['name' => 'Turf A'], $this->hours('06:00', '23:00'));
        $customer = $this->makeCustomer($turf);
        $this->actingAs($this->ownerOf($turf));

        $this->get($this->appUrl('/services'))->assertNotFound();

        $this->post($this->appUrl('/appointments'), [
            'customer_id' => $customer->id,
            'booking_resource_id' => $pitch->id,
            'starts_at' => '2026-10-06T19:00',
        ])->assertSessionHasErrors('duration_minutes');

        $this->post($this->appUrl('/appointments'), [
            'customer_id' => $customer->id,
            'booking_resource_id' => $pitch->id,
            'service_id' => 1,
            'starts_at' => '2026-10-06T19:00',
            'duration_minutes' => 60,
        ])->assertSessionHasErrors('service_id');

        $this->post($this->appUrl('/appointments'), [
            'customer_id' => $customer->id,
            'booking_resource_id' => $pitch->id,
            'starts_at' => '2026-10-06T19:00',
            'duration_minutes' => 60,
            'price' => 1500,
        ])->assertSessionHasNoErrors();

        $appointment = $this->inTenant($turf, fn () => Appointment::query()->sole());
        $this->assertNull($appointment->service_id);
        $this->assertSame('1500.00', $appointment->price);
    }

    public function test_permissions_are_enforced(): void
    {
        $tenant = $this->createTenant();
        $resource = $this->makeResource($tenant);
        $service = $this->makeService($tenant, [], [$resource]);
        $appointment = $this->book($tenant, $resource, '2026-10-06 11:00');
        $customer = $this->makeCustomer($tenant);

        $sales = User::factory()->create();
        $this->addMember($tenant, $sales, 'sales_executive');
        $this->actingAs($sales);
        $this->get($this->appUrl('/appointments'))->assertOk();
        $this->get($this->appUrl('/services'))->assertOk();
        $this->get($this->appUrl('/services/create'))->assertForbidden();
        $this->get($this->appUrl('/resources/create'))->assertForbidden();
        $this->put($this->appUrl("/appointments/{$appointment->id}"), ['notes' => 'x'])->assertForbidden();
        $this->patch($this->appUrl("/appointments/{$appointment->id}/reschedule"), ['starts_at' => '2026-10-06T15:00'])->assertForbidden();
        $this->patch($this->appUrl("/appointments/{$appointment->id}/status"), ['status' => 'cancelled'])->assertForbidden();
        $this->post($this->appUrl('/appointments'), [
            'customer_id' => $customer->id,
            'booking_resource_id' => $resource->id,
            'service_id' => $service->id,
            'starts_at' => '2026-10-06T15:00',
        ])->assertSessionHasNoErrors();

        $accountant = User::factory()->create();
        $this->addMember($tenant, $accountant, 'accountant');
        $this->actingAs($accountant);
        $this->get($this->appUrl('/appointments'))->assertForbidden();
        $this->get($this->appUrl("/appointments/{$appointment->id}"))->assertForbidden();
        $this->get($this->appUrl('/resources'))->assertForbidden();
        $this->get($this->appUrl('/services'))->assertOk();

        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');
        $this->actingAs($receptionist);
        $this->put($this->appUrl('/settings/booking'), ['slot_interval' => 30, 'auto_confirm' => true, 'resource_label' => 'Stylist', 'default_hours' => []])->assertForbidden();
        $this->patch($this->appUrl("/appointments/{$appointment->id}/status"), ['status' => 'cancelled'])->assertSessionHasNoErrors();

        $this->assertSame(AppointmentStatus::Cancelled, $this->inTenant($tenant, fn () => $appointment->fresh())->status);
    }

    public function test_staff_can_filter_the_calendar_to_their_own_column(): void
    {
        $tenant = $this->createTenant();
        $staff = User::factory()->create();
        $membership = $this->addMember($tenant, $staff, 'staff');
        $own = $this->makeResource($tenant, ['name' => 'Sana', 'tenant_user_id' => $membership->id]);
        $other = $this->makeResource($tenant);
        $this->book($tenant, $own, '2026-10-06 11:00');
        $this->book($tenant, $other, '2026-10-06 11:00');

        $this->actingAs($staff)
            ->get($this->appUrl('/appointments?date=2026-10-06&resource=mine'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('myResourceId', $own->id)
                ->where('filter', 'mine')
                ->has('resources', 1)
                ->where('resources.0.name', 'Sana')
                ->has('appointments', 1));
    }
}
