<?php

namespace Tests\Feature\Website;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Customer\Models\Customer;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OnlineBookingTest extends TestCase
{
    use CreatesAutomations, CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const SALON = 'abc-salon.autowave.test';

    private Tenant $tenant;

    private BookingResource $sana;

    private BookingResource $riya;

    private Service $haircut;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday 5 October 2026, 10:00 in India.
        $this->travelToBookingDay();
        $this->tenant = $this->createTenant();
        $this->sana = $this->makeResource($this->tenant, ['name' => 'Sana']);
        $this->riya = $this->makeResource($this->tenant, ['name' => 'Riya']);
        $this->haircut = $this->makeService($this->tenant, ['name' => 'Haircut', 'duration_minutes' => 60], [$this->sana, $this->riya]);
    }

    private function online(array $values): void
    {
        $this->inTenant($this->tenant, fn () => app(BookingSettings::class)->update(['online' => [...app(BookingSettings::class)->online(), ...$values]]));
    }

    private function bookOnline(array $data = [], string $host = self::SALON)
    {
        return $this->from($this->siteUrl($host))->post($this->siteUrl($host, '/booking'), [
            'service_id' => $this->haircut->id,
            'starts_at' => $this->local($this->tenant, '2026-10-06 11:00')->toIso8601String(),
            'name' => 'Meera Joshi',
            'phone' => '99887 76655',
            'email' => 'meera@example.com',
            'notes' => 'First visit',
            ...$data,
        ]);
    }

    private function slots(array $query)
    {
        return $this->getJson($this->siteUrl(self::SALON, '/booking/slots?'.http_build_query($query)));
    }

    public function test_the_booking_section_lists_bookable_services_and_staff(): void
    {
        $this->get($this->siteUrl(self::SALON))
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.uses_services', true)
                ->where('booking.allow_any_resource', true)
                ->where('booking.window', ['first' => '2026-10-05', 'last' => '2026-11-04'])
                ->where('booking.services.0.id', $this->haircut->id)
                ->where('booking.resources', fn ($resources) => collect($resources)->pluck('name')->sort()->values()->all() === ['Riya', 'Sana'])
                ->where('sections', fn ($sections) => collect($sections)->contains('type', 'booking')));
    }

    public function test_slots_respect_the_minimum_notice_and_the_booking_window(): void
    {
        $this->slots(['service_id' => $this->haircut->id, 'date' => '2026-10-05'])
            ->assertOk()
            ->assertJsonPath('slots.0.time', '11:00');

        $this->online(['min_notice_minutes' => 240]);
        $this->slots(['service_id' => $this->haircut->id, 'date' => '2026-10-05'])->assertJsonPath('slots.0.time', '14:00');

        $this->slots(['service_id' => $this->haircut->id, 'date' => '2026-11-05'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->slots(['service_id' => $this->haircut->id, 'date' => '2026-10-04'])->assertStatus(422);
        $this->slots(['service_id' => $this->haircut->id, 'date' => 'soon'])->assertStatus(422);
    }

    public function test_any_available_slot_stays_free_until_every_resource_is_taken(): void
    {
        $this->book($this->tenant, $this->sana, '2026-10-06 11:00');

        $times = fn (?int $resource) => collect($this->slots(array_filter(['service_id' => $this->haircut->id, 'resource_id' => $resource, 'date' => '2026-10-06']))->json('slots'))->pluck('time');

        $this->assertNotContains('11:00', $times($this->sana->id));
        $this->assertContains('11:00', $times($this->riya->id));
        $this->assertContains('11:00', $times(null));

        $this->book($this->tenant, $this->riya, '2026-10-06 11:00');
        $this->assertNotContains('11:00', $times(null));
    }

    public function test_an_online_booking_creates_a_pending_appointment_and_the_customer(): void
    {
        $this->bookOnline(['resource_id' => $this->riya->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->siteUrl(self::SALON))
            ->assertSessionHas('booking_confirmation', fn (array $confirmation) => $confirmation['status'] === 'pending'
                && $confirmation['service'] === 'Haircut'
                && $confirmation['resource'] === 'Riya');

        $this->inTenant($this->tenant, function () {
            $appointment = Appointment::query()->with('customer')->sole();
            $this->assertSame(AppointmentStatus::Pending, $appointment->status);
            $this->assertSame('website', $appointment->source);
            $this->assertSame($this->riya->id, $appointment->booking_resource_id);
            $this->assertTrue($appointment->starts_at->equalTo($this->local($this->tenant, '2026-10-06 11:00')));
            $this->assertSame(60, (int) $appointment->starts_at->diffInMinutes($appointment->ends_at));
            $this->assertSame('First visit', $appointment->notes);
            $this->assertNull($appointment->created_by_user_id);

            $customer = $appointment->customer;
            $this->assertSame('Meera Joshi', $customer->name);
            $this->assertSame('+919988776655', $customer->phone_normalized);
            $this->assertSame('online_booking', $customer->activities()->where('type', 'created')->sole()->metadata['via']);
            $this->assertSame('website', $customer->activities()->where('type', 'appointment_booked')->sole()->metadata['source']);
        });
    }

    public function test_auto_confirm_and_returning_customers(): void
    {
        $this->online(['auto_confirm' => true]);
        $existing = $this->makeCustomer($this->tenant, ['name' => 'Meera', 'phone' => '+91 99887 76655']);

        $this->bookOnline()->assertSessionHasNoErrors();

        $this->inTenant($this->tenant, function () use ($existing) {
            $appointment = Appointment::query()->sole();
            $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
            $this->assertSame($existing->id, $appointment->customer_id);
            $this->assertSame(1, Customer::query()->count());
        });
    }

    public function test_any_available_books_the_next_free_resource_and_refuses_when_full(): void
    {
        $this->book($this->tenant, $this->sana, '2026-10-06 11:00');

        $this->bookOnline()->assertSessionHasNoErrors();
        $this->assertSame($this->riya->id, $this->inTenant($this->tenant, fn () => Appointment::query()->where('source', 'website')->sole()->booking_resource_id));

        $this->bookOnline(['phone' => '98989 89898'])->assertSessionHasErrors('starts_at');
        $this->bookOnline(['resource_id' => $this->sana->id, 'phone' => '98989 89898'])->assertSessionHasErrors('starts_at');
    }

    public function test_online_booking_rules_are_enforced(): void
    {
        // Inside the minimum notice.
        $this->bookOnline(['starts_at' => $this->local($this->tenant, '2026-10-05 10:30')->toIso8601String()])->assertSessionHasErrors('starts_at');
        // Beyond the booking window.
        $this->bookOnline(['starts_at' => $this->local($this->tenant, '2026-11-20 11:00')->toIso8601String()])->assertSessionHasErrors('starts_at');
        // Outside working hours.
        $this->bookOnline(['starts_at' => $this->local($this->tenant, '2026-10-06 20:00')->toIso8601String()])->assertSessionHasErrors('starts_at');
        // A service nobody offers online, a resource that does not offer it, and missing details.
        $other = $this->makeService($this->tenant, ['name' => 'Facial'], []);
        $this->bookOnline(['service_id' => $other->id])->assertSessionHasErrors('service_id');
        $this->bookOnline(['service_id' => null])->assertSessionHasErrors('service_id');
        $inactive = $this->makeResource($this->tenant, ['name' => 'Old', 'is_active' => false]);
        $this->bookOnline(['resource_id' => $inactive->id])->assertSessionHasErrors('resource_id');
        $this->bookOnline(['name' => '', 'phone' => 'abc'])->assertSessionHasErrors(['name', 'phone']);

        $this->online(['allow_any_resource' => false]);
        $this->bookOnline()->assertSessionHasErrors('resource_id');

        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Appointment::query()->count()));
    }

    public function test_turf_bookings_use_the_slot_length_without_services(): void
    {
        $turf = $this->createTenant('Green Turf', 'turf');
        $pitch = $this->makeResource($turf, ['name' => 'Turf A']);

        $this->from($this->siteUrl('green-turf.autowave.test'))->post($this->siteUrl('green-turf.autowave.test', '/booking'), [
            'starts_at' => $this->local($turf, '2026-10-06 17:00')->toIso8601String(),
            'name' => 'Rohit',
            'phone' => '9876501234',
        ])->assertSessionHasNoErrors();

        $this->inTenant($turf, function () use ($pitch) {
            $appointment = Appointment::query()->sole();
            $this->assertSame($pitch->id, $appointment->booking_resource_id);
            $this->assertNull($appointment->service_id);
            $this->assertSame(60, (int) $appointment->starts_at->diffInMinutes($appointment->ends_at));
        });

        // Bookings stay with the business whose website took them.
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Appointment::query()->count()));
    }

    public function test_online_booking_can_be_switched_off(): void
    {
        $this->online(['enabled' => false]);

        $this->slots(['service_id' => $this->haircut->id, 'date' => '2026-10-06'])->assertNotFound();
        $this->bookOnline()->assertNotFound();
        $this->get($this->siteUrl(self::SALON))
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking', null)
                ->where('sections', fn ($sections) => ! collect($sections)->contains('type', 'booking')));
    }

    public function test_honeypot_and_rate_limit(): void
    {
        $this->bookOnline(['company_website' => 'x'])->assertRedirect();
        $this->assertSame(0, $this->inTenant($this->tenant, fn () => Appointment::query()->count()));

        // The honeypot request above counts too.
        config(['booking.online_per_hour' => 3]);
        $this->bookOnline(['name' => ''])->assertSessionHasErrors('name');
        $this->bookOnline(['name' => ''])->assertSessionHasErrors('name');
        $this->bookOnline()->assertStatus(429);
    }

    public function test_automations_can_tell_online_bookings_apart(): void
    {
        $this->pauseDefaultAutomations($this->tenant);
        $this->makeAutomation($this->tenant, 'appointment.created', [
            ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'appointment.source', 'operator' => 'equals', 'value' => 'website']]]],
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Thanks for booking online, {{customer.name}}!']],
        ]);

        $this->book($this->tenant, $this->sana, '2026-10-07 11:00');
        $this->bookOnline()->assertSessionHasNoErrors();

        $messages = $this->inTenant($this->tenant, fn () => OutboundMessage::query()->get());
        $this->assertCount(1, $messages);
        $this->assertSame('+919988776655', $messages->first()->recipient);
    }
}
