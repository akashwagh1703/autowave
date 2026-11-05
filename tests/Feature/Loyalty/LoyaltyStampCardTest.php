<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Loyalty\Actions\EarnFromOrder;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Domain\Module\Services\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LoyaltyStampCardTest extends TestCase
{
    use CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const CAFE = 'abc-cafe.autowave.test';

    public function test_cafe_and_salon_get_loyalty_by_default(): void
    {
        $cafe = $this->createTenant('ABC Cafe', 'cafe');
        $salon = $this->createTenant();
        $turf = $this->createTenant('Green Turf', 'turf');

        $this->assertTrue($this->inTenant($cafe, fn () => app(ModuleManager::class)->isEnabled($cafe, 'loyalty')));
        $this->assertTrue($this->inTenant($salon, fn () => app(ModuleManager::class)->isEnabled($salon, 'loyalty')));
        $this->assertFalse($this->inTenant($turf, fn () => app(ModuleManager::class)->isEnabled($turf, 'loyalty')));
    }

    public function test_walk_in_joins_via_qr_and_earns_stamps_on_completed_order(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $this->inTenant($tenant, function () {
            app(LoyaltySettings::class)->update([
                'enabled' => true,
                'stamps_required' => 10,
                'earn_per' => 'item',
                'product_ids' => [],
                'reward_label' => 'Free coffee',
            ]);
        });

        $coffee = $this->makeProduct($tenant, ['name' => 'Coffee', 'price' => '80.00']);

        $this->from($this->siteUrl(self::CAFE))
            ->post($this->siteUrl(self::CAFE, '/loyalty/join'), [
                'name' => 'Priya Shah',
                'phone' => '98765 43210',
                'company_website' => '',
            ])
            ->assertRedirect();

        $account = $this->inTenant($tenant, fn () => LoyaltyAccount::query()->with('customer')->sole());
        $this->assertSame('Priya Shah', $account->customer->name);
        $this->assertSame(0, $account->stamps_balance);

        $this->get($this->siteUrl(self::CAFE, '/loyalty/card/'.$account->public_token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('website/LoyaltyCard')
                ->where('loyalty.stamps_required', 10)
                ->where('loyalty.reward_label', 'Free coffee'));

        $order = $this->placeOrder($tenant, [[$coffee, 2]], [
            'customer_id' => $account->customer_id,
            'completed' => true,
        ]);

        $account = $this->inTenant($tenant, fn () => $account->fresh());
        $this->assertSame(2, $account->stamps_balance);
        $this->assertSame(2, $account->lifetime_stamps);
        $this->assertSame(OrderStatus::Completed, $order->status);

        // Same order must not stamp twice.
        $this->inTenant($tenant, fn () => app(EarnFromOrder::class)->handle($order));
        $this->assertSame(2, $this->inTenant($tenant, fn () => $account->fresh()->stamps_balance));
    }

    public function test_staff_redeems_reward_after_enough_stamps(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $this->inTenant($tenant, fn () => app(LoyaltySettings::class)->update([
            'enabled' => true,
            'stamps_required' => 3,
            'earn_per' => 'order',
            'reward_label' => 'Free coffee',
        ]));

        $product = $this->makeProduct($tenant, ['name' => 'Coffee', 'price' => '80.00']);
        $customer = $this->makeCustomer($tenant, ['name' => 'Ravi', 'phone' => '9988776655']);

        foreach (range(1, 3) as $_) {
            $this->placeOrder($tenant, [[$product, 1]], ['customer_id' => $customer->id, 'completed' => true]);
        }

        $account = $this->inTenant($tenant, fn () => LoyaltyAccount::query()->where('customer_id', $customer->id)->sole());
        $this->assertSame(3, $account->stamps_balance);

        $this->actingAs($this->ownerOf($tenant))
            ->post($this->appUrl('/customers/'.$customer->id.'/loyalty/redeem'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $account = $this->inTenant($tenant, fn () => $account->fresh());
        $this->assertSame(0, $account->stamps_balance);
        $this->assertSame(1, $account->rewards_redeemed);
    }

    public function test_owner_updates_loyalty_settings(): void
    {
        $tenant = $this->createTenant('ABC Cafe', 'cafe');
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/settings/loyalty'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/settings/Loyalty')
            ->where('loyalty.enabled', true)
            ->where('loyalty.stamps_required', 10));

        $this->put($this->appUrl('/settings/loyalty'), [
            'enabled' => true,
            'stamps_required' => 8,
            'earn_per' => 'order',
            'product_ids' => [],
            'earn_appointments' => false,
            'reward_label' => 'Free pastry',
            'join_heading' => 'Join us',
            'join_blurb' => 'Collect stamps.',
            'collect_birthday' => true,
            'birthday_mode' => 'day',
            'birthday_grant_reward' => false,
            'birthday_reward_label' => 'Birthday treat',
        ])->assertSessionHasNoErrors();

        $this->assertSame(8, $this->inTenant($tenant, fn () => app(LoyaltySettings::class)->all()['stamps_required']));
        $this->assertSame('Free pastry', $this->inTenant($tenant, fn () => app(LoyaltySettings::class)->all()['reward_label']));
    }
}
