<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Customer\Events\CustomerBirthday;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\BirthdayGreetings;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Support\LoyaltySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BirthdayGreetingsTest extends TestCase
{
    use CreatesAutomations, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_birthday_is_collected_on_loyalty_join_and_greeted_once(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(10, 0));

        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $this->pauseDefaultAutomations($tenant);
        $this->inTenant($tenant, fn () => app(LoyaltySettings::class)->update([
            'enabled' => true,
            'collect_birthday' => true,
            'birthday_mode' => 'day',
            'birthday_grant_reward' => true,
            'stamps_required' => 10,
            'birthday_reward_label' => 'Birthday coffee',
        ]));

        $this->from($this->siteUrl('abc-cafe.autowave.test'))
            ->post($this->siteUrl('abc-cafe.autowave.test', '/loyalty/join'), [
                'name' => 'Priya Shah',
                'phone' => '9876543210',
                'date_of_birth' => '1995-11-05',
                'company_website' => '',
            ])
            ->assertRedirect();

        $customer = $this->inTenant($tenant, fn () => Customer::query()->sole());
        $this->assertSame('1995-11-05', $customer->date_of_birth->toDateString());

        Event::fake([CustomerBirthday::class]);

        $result = $this->inTenant($tenant, fn () => app(BirthdayGreetings::class)->run());
        $this->assertSame(['greeted' => 1, 'rewards' => 1], $result);
        Event::assertDispatched(CustomerBirthday::class);

        $account = $this->inTenant($tenant, fn () => LoyaltyAccount::query()->sole());
        $this->assertSame(10, $account->stamps_balance);

        // Same day again: already greeted this year.
        $again = $this->inTenant($tenant, fn () => app(BirthdayGreetings::class)->run());
        $this->assertSame(['greeted' => 0, 'rewards' => 0], $again);
    }

    public function test_owner_saves_customer_birthday_and_loyalty_birthday_settings(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/customers'), [
            'name' => 'Ravi Kumar',
            'phone' => '9988776655',
            'date_of_birth' => '1990-03-15',
            'tags' => [],
        ])->assertSessionHasNoErrors();

        $customer = $this->inTenant($tenant, fn () => Customer::query()->sole());
        $this->assertSame('1990-03-15', $customer->date_of_birth->toDateString());

        $this->put($this->appUrl('/settings/loyalty'), [
            'enabled' => true,
            'stamps_required' => 10,
            'earn_per' => 'item',
            'product_ids' => [],
            'earn_appointments' => false,
            'reward_label' => 'Free coffee',
            'join_heading' => 'Join',
            'join_blurb' => 'Collect stamps',
            'collect_birthday' => true,
            'birthday_mode' => 'month',
            'birthday_grant_reward' => true,
            'birthday_reward_label' => 'Birthday treat',
        ])->assertSessionHasNoErrors();

        $this->assertSame('month', $this->inTenant($tenant, fn () => app(LoyaltySettings::class)->all()['birthday_mode']));
        $this->assertTrue($this->inTenant($tenant, fn () => app(LoyaltySettings::class)->all()['birthday_grant_reward']));
    }
}
