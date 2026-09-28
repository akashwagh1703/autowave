<?php

namespace Tests\Feature\Commerce;

use App\Domain\Automation\Models\Automation;
use App\Domain\Commerce\Actions\ChangeOrderStatus;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCommerceRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CommerceSettingsTest extends TestCase
{
    use CreatesAutomations, CreatesCommerceRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private function settingsPayload(array $overrides = []): array
    {
        return [
            'enabled' => true,
            'auto_confirm' => true,
            'pickup' => true,
            'delivery' => true,
            'delivery_fee' => '49.5',
            'free_delivery_over' => '999',
            'min_order' => '',
            'delivery_note' => '  Within 5 km  ',
            ...$overrides,
        ];
    }

    public function test_business_types_provision_their_online_ordering_defaults(): void
    {
        $salon = $this->createTenant();
        $store = $this->createTenant('Corner Store', 'local_store');

        $this->assertSame(['pickup'], $this->inTenant($salon, fn () => app(CommerceSettings::class)->onlineFulfilment()));
        $this->assertSame(['pickup', 'delivery'], $this->inTenant($store, fn () => app(CommerceSettings::class)->onlineFulfilment()));

        $templates = fn ($tenant) => $this->inTenant($tenant, fn () => Automation::query()->whereIn('template_key', ['new_online_order_alert', 'order_ready'])->pluck('is_active', 'template_key')->all());
        $this->assertSame(['new_online_order_alert' => true, 'order_ready' => false], $templates($store));
    }

    public function test_the_owner_updates_online_ordering(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/settings/commerce'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/settings/Commerce')
            ->where('online.enabled', true)
            ->where('online.delivery', false));

        $this->put($this->appUrl('/settings/commerce'), $this->settingsPayload())->assertSessionHasNoErrors();

        $this->assertSame([
            'enabled' => true,
            'auto_confirm' => true,
            'pickup' => true,
            'delivery' => true,
            'delivery_fee' => '49.50',
            'free_delivery_over' => '999.00',
            'min_order' => null,
            'delivery_note' => 'Within 5 km',
        ], $this->inTenant($tenant, fn () => app(CommerceSettings::class)->online()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'commerce.settings_updated']);

        $this->put($this->appUrl('/settings/commerce'), $this->settingsPayload(['pickup' => false, 'delivery' => false]))->assertSessionHasErrors('pickup');
        $this->put($this->appUrl('/settings/commerce'), $this->settingsPayload(['delivery_fee' => '-1']))->assertSessionHasErrors('delivery_fee');
        // With online ordering off, offering neither is fine.
        $this->put($this->appUrl('/settings/commerce'), $this->settingsPayload(['enabled' => false, 'pickup' => false, 'delivery' => false]))->assertSessionHasNoErrors();
    }

    public function test_only_members_who_manage_settings_can_change_them(): void
    {
        $tenant = $this->createTenant();
        $manager = User::factory()->create();
        $this->addMember($tenant, $manager, 'manager');
        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');

        $this->actingAs($manager)->get($this->appUrl('/settings/commerce'))->assertOk();
        $this->actingAs($manager)->put($this->appUrl('/settings/commerce'), $this->settingsPayload())->assertForbidden();
        $this->actingAs($receptionist)->get($this->appUrl('/settings/commerce'))->assertForbidden();

        $turf = $this->createTenant('Green Turf', 'turf');
        $this->actingAs($this->ownerOf($turf))->get($this->appUrl('/settings/commerce'))->assertNotFound();
    }

    public function test_dashboard_widgets_show_live_commerce_figures(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $store = $this->createTenant('Corner Store', 'local_store');
        $rice = $this->makeProduct($store, ['name' => 'Rice 5 kg', 'price' => '400.00'], stock: 20);
        $soap = $this->makeProduct($store, ['name' => 'Soap', 'price' => '35.00', 'low_stock_threshold' => 10], stock: 12);
        $customer = $this->makeCustomer($store);

        $this->placeOrder($store, [[$rice, 1], [$soap, 3]], ['customer_id' => $customer->id, 'completed' => true]);
        $this->placeOrder($store, [[$rice, 1]], ['customer_id' => $customer->id, 'completed' => true]);
        $open = $this->placeOrder($store, [[$rice, 2]]);
        $cancelled = $this->placeOrder($store, [[$rice, 1]]);
        $this->inTenant($store, fn () => app(ChangeOrderStatus::class)->handle($cancelled, OrderStatus::Cancelled));

        $this->actingAs($this->ownerOf($store))->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('metrics.orders_today.value', 3)
            ->where('metrics.revenue_today.value', '905.00')
            ->where('metrics.low_stock.value', 1)
            ->where('metrics.repeat_customers.value', 1));

        $this->assertSame(OrderStatus::Confirmed, $open->status);

        // Revenue is a money figure: only members with reports access see it.
        $receptionist = User::factory()->create();
        $this->addMember($store, $receptionist, 'receptionist');
        $this->actingAs($receptionist)->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('metrics.orders_today.value', 3)
            ->missing('metrics.revenue_today')
            ->missing('metrics.low_stock'));
    }

    public function test_order_triggers_run_automations_with_order_variables(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $product = $this->makeProduct($tenant, ['name' => 'Hair serum', 'price' => '450.00']);
        $customer = $this->makeCustomer($tenant, ['name' => 'Asha', 'phone' => '+91 98765 43210']);

        $this->makeAutomation($tenant, 'order.ready', [
            ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'order.fulfilment', 'operator' => 'equals', 'value' => 'pickup']]]],
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hi {{customer.name}}, order {{order.number}} ({{order.items}}, {{order.total}}) is ready.']],
        ]);

        $pickup = $this->placeOrder($tenant, [[$product, 2]], ['customer_id' => $customer->id, 'fulfilment' => 'pickup']);
        $inStore = $this->placeOrder($tenant, [[$product, 1]], ['customer_id' => $customer->id]);
        $this->inTenant($tenant, fn () => app(ChangeOrderStatus::class)->handle($pickup, OrderStatus::Ready));
        $this->inTenant($tenant, fn () => app(ChangeOrderStatus::class)->handle($inStore, OrderStatus::Ready));

        $message = $this->inTenant($tenant, fn () => OutboundMessage::query()->sole());
        $this->assertSame('+919876543210', $message->recipient);
        $this->assertSame('Hi Asha, order #1001 (2 × Hair serum, 900.00) is ready.', $message->body);
    }
}
