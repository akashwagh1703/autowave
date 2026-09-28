<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Actions\SaveBookingResource;
use App\Domain\Booking\Support\BookingSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingSettingsTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_settings_default_to_the_business_type_then_the_platform(): void
    {
        $salon = $this->createTenant();
        $turf = $this->createTenant('Green Turf', 'turf');

        $this->actingAs($this->ownerOf($salon))
            ->get($this->appUrl('/settings/booking'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.slot_interval', config('booking.slot_interval'))
                ->where('settings.auto_confirm', true)
                ->where('settings.resource_label', 'Staff')
                ->has('settings.default_hours', 6)
                ->where('slotIntervals', config('booking.slot_intervals')));

        $this->actingAs($this->ownerOf($turf))
            ->get($this->appUrl('/settings/booking'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.slot_interval', 60)
                ->where('settings.resource_label', 'Turf')
                ->has('settings.default_hours', 7));
    }

    public function test_the_owner_can_update_booking_settings(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->put($this->appUrl('/settings/booking'), [
            'slot_interval' => 30,
            'auto_confirm' => false,
            'resource_label' => '  Stylist ',
            'default_hours' => [
                ['weekday' => 2, 'starts_at' => '11:00', 'ends_at' => '19:00'],
                ['weekday' => 1, 'starts_at' => '11:00', 'ends_at' => '19:00'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->inTenant($tenant, function () {
            $settings = app(BookingSettings::class);
            $this->assertSame(30, $settings->slotInterval());
            $this->assertFalse($settings->autoConfirm());
            $this->assertSame(['singular' => 'Stylist', 'plural' => 'Stylists'], $settings->resourceLabels());
            $this->assertSame([1, 2], array_column($settings->defaultHours(), 'weekday'));

            // New resources pick up the new default hours.
            $resource = app(SaveBookingResource::class)->handle(['name' => 'Sana']);
            $this->assertSame([1, 2], $resource->workingHours()->pluck('weekday')->all());
        });

        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'booking.settings_updated']);
    }

    public function test_invalid_settings_are_rejected(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $valid = ['slot_interval' => 30, 'auto_confirm' => true, 'resource_label' => 'Stylist', 'default_hours' => $this->hours('10:00', '18:00', [1])];

        $this->put($this->appUrl('/settings/booking'), [...$valid, 'slot_interval' => 7])->assertSessionHasErrors('slot_interval');
        $this->put($this->appUrl('/settings/booking'), [...$valid, 'resource_label' => 'x'])->assertSessionHasErrors('resource_label');
        $this->put($this->appUrl('/settings/booking'), [...$valid, 'default_hours' => [['weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '10:00']]])->assertSessionHasErrors('default_hours.0');
        $this->put($this->appUrl('/settings/booking'), [...$valid, 'default_hours' => [
            ['weekday' => 1, 'starts_at' => '10:00', 'ends_at' => '14:00'],
            ['weekday' => 1, 'starts_at' => '13:00', 'ends_at' => '18:00'],
        ]])->assertSessionHasErrors('default_hours');

        $this->assertSame(config('booking.slot_interval'), $this->inTenant($tenant, fn () => app(BookingSettings::class)->slotInterval()));
    }

    public function test_managers_can_view_but_not_change_booking_settings(): void
    {
        $tenant = $this->createTenant();
        $manager = User::factory()->create();
        $this->addMember($tenant, $manager, 'manager');
        $this->actingAs($manager);

        $this->get($this->appUrl('/settings/booking'))->assertOk();
        $this->put($this->appUrl('/settings/booking'), ['slot_interval' => 30, 'auto_confirm' => true, 'resource_label' => 'Stylist', 'default_hours' => []])->assertForbidden();
    }

    public function test_an_unsupported_stored_interval_falls_back_to_the_default(): void
    {
        $tenant = $this->createTenant();

        $this->inTenant($tenant, function () {
            app(BookingSettings::class)->update(['slot_interval' => 7]);
            $this->assertSame(config('booking.slot_interval'), app(BookingSettings::class)->slotInterval());
        });
    }
}
