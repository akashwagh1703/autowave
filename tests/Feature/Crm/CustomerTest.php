<?php

namespace Tests\Feature\Crm;

use App\Domain\Activity\Models\Activity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Events\CustomerCreated;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Actions\ConvertLead;
use App\Domain\Module\Models\Module;
use App\Domain\Module\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_owner_can_add_a_customer_with_tags(): void
    {
        Event::fake([CustomerCreated::class]);
        $tenant = $this->createTenant();

        $this->actingAs($this->ownerOf($tenant))
            ->post($this->appUrl('/customers'), [
                'name' => 'Kavya Rao',
                'phone' => '98220 11111',
                'email' => 'kavya@example.com',
                'city' => 'Pune',
                'tags' => ['VIP', ' vip ', 'Bridal', ''],
            ])
            ->assertSessionHasNoErrors();

        $customer = Customer::withoutTenantScope()->sole();
        $this->assertSame($tenant->id, $customer->tenant_id);
        $this->assertSame(['VIP', 'Bridal'], $customer->tags);
        $this->assertSame('+919822011111', $customer->phone_normalized);
        Event::assertDispatched(CustomerCreated::class);
    }

    public function test_one_live_customer_per_phone_number(): void
    {
        $tenant = $this->createTenant();
        $existing = $this->makeCustomer($tenant, ['phone' => '9822011111']);

        $this->actingAs($this->ownerOf($tenant))
            ->post($this->appUrl('/customers'), ['name' => 'Copy', 'phone' => '+91 98220 11111'])
            ->assertSessionHasErrors('phone');

        // A deleted customer frees the number.
        $this->delete($this->appUrl("/customers/{$existing->id}"))->assertRedirect(route('customers.index'));
        $this->post($this->appUrl('/customers'), ['name' => 'New owner', 'phone' => '+91 98220 11111'])->assertSessionHasNoErrors();
        $this->assertTrue(AuditLog::query()->where('action', 'customer.deleted')->where('subject_id', $existing->id)->exists());
    }

    public function test_index_searches_and_filters_by_tag(): void
    {
        $tenant = $this->createTenant();
        $this->makeCustomer($tenant, ['name' => 'Kavya Rao', 'tags' => ['VIP']]);
        $this->makeCustomer($tenant, ['name' => 'Rohan Mehta', 'city' => 'Mumbai', 'tags' => ['Regular']]);

        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/customers'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/customers/Index')
                ->where('customers.meta.total', 2)
                ->where('tags', ['Regular', 'VIP']));
        $this->get($this->appUrl('/customers?tag=VIP'))->assertInertia(fn (Assert $page) => $page->where('customers.meta.total', 1)->where('customers.data.0.name', 'Kavya Rao'));
        $this->get($this->appUrl('/customers?search=mumbai'))->assertInertia(fn (Assert $page) => $page->where('customers.data.0.name', 'Rohan Mehta'));
    }

    public function test_the_customer_timeline_includes_converted_lead_history(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $lead = $this->makeLead($tenant, ['name' => 'Priya', 'phone' => '9876500011'], $owner);
        $this->actingAs($owner)->post($this->appUrl("/leads/{$lead->id}/activities"), ['type' => 'call', 'body' => 'Interested']);
        $converted = $this->inTenant($tenant, fn () => app(ConvertLead::class)->handle($lead->fresh(), $owner));

        $this->get($this->appUrl("/customers/{$converted->customer_id}"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/customers/Show')
                ->where('customer.name', 'Priya')
                ->has('leads', 1)
                ->where('activities', fn ($activities) => collect($activities)->pluck('type')->sort()->values()->all() === ['call', 'converted', 'created', 'created']));
    }

    public function test_notes_can_be_logged_on_a_customer(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant);

        $this->actingAs($this->ownerOf($tenant))
            ->post($this->appUrl("/customers/{$customer->id}/activities"), ['type' => 'note', 'body' => 'Prefers Saturdays'])
            ->assertSessionHasNoErrors();

        $this->inTenant($tenant, fn () => $this->assertSame('Prefers Saturdays', Activity::query()->where('customer_id', $customer->id)->where('type', 'note')->sole()->body));
    }

    public function test_updating_a_customer_records_the_change(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant, ['name' => 'Before']);

        $this->actingAs($this->ownerOf($tenant))
            ->put($this->appUrl("/customers/{$customer->id}"), ['name' => 'After', 'phone' => $customer->phone, 'tags' => ['VIP']])
            ->assertRedirect(route('customers.show', $customer));

        $this->assertSame('After', $customer->fresh()->name);
        $this->inTenant($tenant, fn () => $this->assertEqualsCanonicalizing(['name', 'tags'], Activity::query()->where('customer_id', $customer->id)->where('type', 'updated')->sole()->metadata['changed']));
    }

    public function test_customer_permissions_follow_roles(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant);
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');

        $this->actingAs($staff);

        $this->get($this->appUrl('/customers'))->assertOk();
        $this->get($this->appUrl("/customers/{$customer->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('customer.id', $customer->id));
        $this->get($this->appUrl('/customers/create'))->assertForbidden();
        $this->put($this->appUrl("/customers/{$customer->id}"), ['name' => 'Nope'])->assertForbidden();
        $this->post($this->appUrl("/customers/{$customer->id}/activities"), ['type' => 'note', 'body' => 'x'])->assertForbidden();
        $this->delete($this->appUrl("/customers/{$customer->id}"))->assertForbidden();
    }

    public function test_customers_are_hidden_when_the_module_is_disabled(): void
    {
        $tenant = $this->createTenant();
        $customer = $this->makeCustomer($tenant);

        // Every preset's engines require customers, so switch the row off directly.
        TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('module_id', Module::query()->where('code', 'customers')->select('id'))
            ->update(['enabled' => false]);

        $this->actingAs($this->ownerOf($tenant));
        $this->get($this->appUrl('/customers'))->assertNotFound();
        $this->get($this->appUrl("/customers/{$customer->id}"))->assertNotFound();
    }
}
