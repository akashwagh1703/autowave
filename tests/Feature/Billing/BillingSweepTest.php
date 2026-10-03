<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Notifications\SubscriptionReminder;
use App\Domain\Billing\Support\BillingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class BillingSweepTest extends TestCase
{
    use CreatesTenants, ManagesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-05 10:00', 'Asia/Kolkata'));
    }

    /** @return list<string> */
    private function sentTypes(): array
    {
        return Notification::sent($this->owner, SubscriptionReminder::class)->map(fn (SubscriptionReminder $reminder) => $reminder->type.($reminder->days ? ":{$reminder->days}" : ''))->all();
    }

    private $owner;

    public function test_owners_get_each_reminder_once(): void
    {
        $tenant = $this->createTenant();
        $this->owner = $this->ownerOf($tenant);
        $end = $this->subscriptionOf($tenant)->ends_at;

        $this->artisan('billing:sweep');
        $this->assertSame([], $this->sentTypes());

        $this->travelTo($end->copy()->subDays(7)->addHour());
        $this->artisan('billing:sweep');
        $this->artisan('billing:sweep');
        $this->assertSame(['ending:7'], $this->sentTypes());

        $this->travelTo($end->copy()->subHours(20));
        $this->artisan('billing:sweep');
        $this->assertSame(['ending:7', 'ending:1'], $this->sentTypes(), 'A missed 3-day reminder is skipped, not sent late.');

        $this->travelTo($end->copy()->addHour());
        $this->artisan('billing:sweep');
        $this->travelTo($end->copy()->addDays(10));
        $this->artisan('billing:sweep');
        $this->assertSame(['ending:7', 'ending:1', 'ended'], $this->sentTypes(), 'Read-only is only announced while plans are enforced.');

        app(BillingSettings::class)->update(['enforce' => true]);
        $this->artisan('billing:sweep');
        $this->travelTo($end->copy()->addDays(31));
        $this->artisan('billing:sweep');
        $this->artisan('billing:sweep');
        $this->assertSame(['ending:7', 'ending:1', 'ended', 'read_only', 'locked'], $this->sentTypes());
    }

    public function test_the_internal_business_gets_no_reminders(): void
    {
        $tenant = $this->createTenant('AutoWave', options: ['is_internal' => true]);
        $this->owner = $this->ownerOf($tenant);
        $this->endsAt($tenant, now()->addDays(2));

        $this->artisan('billing:sweep')->assertSuccessful();

        $this->assertSame([], $this->sentTypes());
    }
}
