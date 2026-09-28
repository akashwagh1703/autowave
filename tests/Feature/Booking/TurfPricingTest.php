<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\AppointmentPayment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Support\ResourceRates;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TurfPricingTest extends TestCase
{
    use CreatesBookingRecords;
    use CreatesCrmRecords;
    use CreatesTenants;
    use RefreshDatabase;

    private const HOST = 'green-turf.autowave.test';

    private Tenant $turf;

    private BookingResource $pitch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelToBookingDay();
        $this->turf = $this->createTenant('Green Turf', 'turf');
        $this->pitch = $this->makeResource($this->turf, [
            'name' => 'Turf A',
            'hourly_rate' => 1000,
            'rates' => [
                ['label' => 'Peak', 'weekdays' => [1, 2, 3, 4, 5], 'from' => '18:00', 'to' => '23:00', 'hourly_rate' => 1400],
                ['label' => 'Weekend', 'weekdays' => [6, 7], 'from' => '06:00', 'to' => '24:00', 'hourly_rate' => 1600],
            ],
        ], $this->hours('06:00', '23:00'));
    }

    private function quote(string $from, string $to): ?string
    {
        return $this->inTenant($this->turf, fn () => ResourceRates::quote(
            $this->pitch->fresh(),
            $this->local($this->turf, $from),
            $this->local($this->turf, $to),
        ));
    }

    public function test_quotes_split_at_rate_boundaries(): void
    {
        // Monday: half an hour at the base rate, half an hour at peak.
        $this->assertSame('1200.00', $this->quote('2026-10-05 17:30', '2026-10-05 18:30'));
        $this->assertSame('1000.00', $this->quote('2026-10-05 10:00', '2026-10-05 11:00'));
        $this->assertSame('2800.00', $this->quote('2026-10-05 19:00', '2026-10-05 21:00'));
        // Saturday uses the weekend rate all day.
        $this->assertSame('1600.00', $this->quote('2026-10-10 10:00', '2026-10-10 11:00'));
    }

    public function test_quotes_are_null_when_rates_do_not_cover_the_booking(): void
    {
        $this->inTenant($this->turf, fn () => $this->pitch->forceFill(['hourly_rate' => null])->save());

        $this->assertSame('1400.00', $this->quote('2026-10-05 18:00', '2026-10-05 19:00'));
        $this->assertNull($this->quote('2026-10-05 17:30', '2026-10-05 18:30'));

        $plain = $this->makeResource($this->turf, ['name' => 'Turf B']);
        $this->assertNull($this->inTenant($this->turf, fn () => ResourceRates::quote(
            $plain, $this->local($this->turf, '2026-10-05 10:00'), $this->local($this->turf, '2026-10-05 11:00'),
        )));
    }

    public function test_bookings_store_the_rate_price_unless_one_is_given(): void
    {
        $this->assertSame('1200.00', (string) $this->book($this->turf, $this->pitch, '2026-10-05 17:30')->price);
        $this->assertSame('3200.00', (string) $this->book($this->turf, $this->pitch, '2026-10-10 10:00', ['duration_minutes' => 120])->price);
        $this->assertSame('900.00', (string) $this->book($this->turf, $this->pitch, '2026-10-06 10:00', ['price' => 900])->price);
    }

    public function test_the_team_slot_finder_shows_prices(): void
    {
        $this->actingAs($this->ownerOf($this->turf))
            ->getJson($this->appUrl('/appointments/availability?'.http_build_query([
                'resource' => $this->pitch->id,
                'date' => '2026-10-05',
                'duration' => 60,
            ])))
            ->assertOk()
            ->assertJsonFragment(['time' => '17:00', 'price' => '1000.00'])
            ->assertJsonFragment(['time' => '18:00', 'price' => '1400.00']);
    }

    public function test_the_website_shows_rates_and_slot_prices(): void
    {
        $this->makeResource($this->turf, ['name' => 'Turf B', 'hourly_rate' => 1500], $this->hours('06:00', '23:00'));

        $this->get($this->siteUrl(self::HOST))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.resources.0.name', 'Turf A')
                ->where('booking.resources.0.hourly_rate', '1000.00')
                ->where('booking.resources.0.rates.0.label', 'Peak')
                ->where('booking.resources.1.hourly_rate', '1500.00'));

        $slots = collect($this->getJson($this->siteUrl(self::HOST, '/booking/slots?date=2026-10-05'))->assertOk()->json('slots'))->keyBy('time');
        $this->assertSame('1000.00', $slots['17:00']['price']);
        $this->assertTrue($slots['17:00']['price_varies']);
        $this->assertSame('1400.00', $slots['18:00']['price']);

        $onePitch = collect($this->getJson($this->siteUrl(self::HOST, '/booking/slots?'.http_build_query([
            'resource_id' => $this->pitch->id, 'date' => '2026-10-10',
        ])))->json('slots'))->keyBy('time');
        $this->assertSame('1600.00', $onePitch['10:00']['price']);
        $this->assertFalse($onePitch['10:00']['price_varies']);

        $this->post($this->siteUrl(self::HOST, '/booking'), [
            'resource_id' => $this->pitch->id,
            'starts_at' => $this->local($this->turf, '2026-10-05 18:00')->toIso8601String(),
            'name' => 'Rohit',
            'phone' => '9876501234',
        ])->assertSessionHasNoErrors();

        $this->assertSame('1400.00', $this->inTenant($this->turf, fn () => (string) Appointment::query()->sole()->price));
    }

    public function test_resource_forms_save_and_validate_rates(): void
    {
        $owner = $this->ownerOf($this->turf);
        $payload = [
            'name' => 'Turf A',
            'color' => '#16a34a',
            'is_active' => true,
            'working_hours' => $this->hours('06:00', '23:00'),
            'hourly_rate' => '1100',
            'rates' => [['label' => 'Night', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'from' => '20:00', 'to' => '24:00', 'hourly_rate' => '1500']],
        ];

        $this->actingAs($owner)->put($this->appUrl('/resources/'.$this->pitch->id), $payload)->assertSessionHasNoErrors();

        $pitch = $this->inTenant($this->turf, fn () => $this->pitch->fresh());
        $this->assertSame('1100.00', (string) $pitch->hourly_rate);
        $this->assertEquals([['label' => 'Night', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'from' => '20:00', 'to' => '24:00', 'hourly_rate' => '1500.00']], $pitch->rates);

        foreach ([
            'rates.0.from' => ['from' => '22:00', 'to' => '20:00'],
            'rates.0.weekdays' => ['weekdays' => []],
            'rates.0.label' => ['label' => ''],
            'rates.0.hourly_rate' => ['hourly_rate' => 'abc'],
        ] as $key => $override) {
            $this->actingAs($owner)->put($this->appUrl('/resources/'.$this->pitch->id), [
                ...$payload,
                'rates' => [[...$payload['rates'][0], ...$override]],
            ])->assertSessionHasErrors($key);
        }

        $this->actingAs($owner)->put($this->appUrl('/resources/'.$this->pitch->id), [...$payload, 'hourly_rate' => null, 'rates' => []])->assertSessionHasNoErrors();
        $this->assertFalse($this->inTenant($this->turf, fn () => $this->pitch->fresh()->hasRates()));
    }

    public function test_advances_are_recorded_as_payments(): void
    {
        $owner = $this->ownerOf($this->turf);
        $appointment = $this->book($this->turf, $this->pitch, '2026-10-05 18:00');

        $this->actingAs($owner)->post($this->appUrl("/appointments/{$appointment->id}/payments"), [
            'amount' => '500', 'method' => 'upi', 'reference' => 'UPI123',
        ])->assertSessionHasNoErrors();

        $appointment = $this->inTenant($this->turf, fn () => $appointment->fresh());
        $this->assertSame('500.00', (string) $appointment->amount_paid);
        $this->assertSame('900.00', $appointment->balance());

        $this->actingAs($owner)->post($this->appUrl("/appointments/{$appointment->id}/payments"), ['amount' => '901', 'method' => 'cash'])
            ->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post($this->appUrl("/appointments/{$appointment->id}/payments"), ['amount' => '100', 'method' => 'bitcoin'])
            ->assertSessionHasErrors('method');

        $this->actingAs($owner)->put($this->appUrl("/appointments/{$appointment->id}"), ['price' => '400'])->assertSessionHasErrors('price');

        $payment = $this->inTenant($this->turf, fn () => AppointmentPayment::query()->sole());
        $this->actingAs($owner)->delete($this->appUrl("/appointments/{$appointment->id}/payments/{$payment->id}"))->assertSessionHasNoErrors();
        $this->assertSame('0.00', $this->inTenant($this->turf, fn () => (string) $appointment->fresh()->amount_paid));
    }

    public function test_advances_need_a_price_and_an_open_appointment(): void
    {
        $owner = $this->ownerOf($this->turf);
        $plain = $this->makeResource($this->turf, ['name' => 'Turf B']);
        $unpriced = $this->book($this->turf, $plain, '2026-10-05 10:00');
        $this->assertNull($unpriced->price);

        $this->actingAs($owner)->post($this->appUrl("/appointments/{$unpriced->id}/payments"), ['amount' => '100', 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $cancelled = $this->book($this->turf, $this->pitch, '2026-10-05 12:00');
        $this->inTenant($this->turf, fn () => $cancelled->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now()])->save());

        $this->actingAs($owner)->post($this->appUrl("/appointments/{$cancelled->id}/payments"), ['amount' => '100', 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, $this->inTenant($this->turf, fn () => AppointmentPayment::query()->count()));
    }

    public function test_payments_stay_inside_their_tenant(): void
    {
        $appointment = $this->book($this->turf, $this->pitch, '2026-10-05 18:00');
        $other = $this->createTenant('Other Turf', 'turf');

        $this->actingAs($this->ownerOf($other))->post($this->appUrl("/appointments/{$appointment->id}/payments"), ['amount' => '100', 'method' => 'cash'])
            ->assertNotFound();

        $this->actingAs($this->ownerOf($this->turf))->post($this->appUrl("/appointments/{$appointment->id}/payments"), ['amount' => '100', 'method' => 'cash']);
        $payment = $this->inTenant($this->turf, fn () => AppointmentPayment::query()->sole());

        $this->actingAs($this->ownerOf($other))->delete($this->appUrl("/appointments/{$appointment->id}/payments/{$payment->id}"))->assertNotFound();
        $this->assertSame(1, $this->inTenant($this->turf, fn () => AppointmentPayment::query()->count()));
    }
}
