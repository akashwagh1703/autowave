<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_crm_widgets_show_live_figures(): void
    {
        $tenant = $this->createTenant();
        $this->makeLead($tenant, ['estimated_value' => 25000, 'next_followup_at' => now()->subHour()]);
        $this->makeLead($tenant, ['estimated_value' => 5000]);
        $this->makeLead($this->createTenant('Other'), ['estimated_value' => 99999]);

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.new_leads.value', 2)
                ->where('metrics.pending_followups.value', 1)
                ->where('metrics.potential_revenue.value', '30000.00')
                ->where('metrics.potential_revenue.type', 'currency')
                ->missing('metrics.product_sales'));
    }

    public function test_widgets_are_hidden_from_users_who_cannot_see_leads(): void
    {
        $tenant = $this->createTenant();
        $this->makeLead($tenant);
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');

        $this->actingAs($staff)
            ->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page->missing('metrics.new_leads')->missing('metrics.pending_followups'));
    }
}
