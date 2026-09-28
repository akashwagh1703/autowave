<?php

namespace Tests\Feature\AI;

use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Models\AIUsage;
use App\Domain\AI\Services\AIService;
use App\Domain\AI\Support\AISettings;
use App\Domain\AI\Support\AIUsageMeter;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Lead\Models\Lead;
use App\Domain\Module\Actions\BackfillTenantModules;
use App\Domain\Module\Models\Module;
use App\Domain\Module\Models\TenantModule;
use App\Domain\Tenant\Models\TenantSetting;
use App\Models\User;
use Database\Seeders\TenantBackfillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAi;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Settings → AI, the Super Admin usage view, caps, module backfill and tenant isolation (ADR-019). */
class AiAdminTest extends TestCase
{
    use CreatesAi, CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_the_owner_sees_usage_and_changes_ai_settings(): void
    {
        $tenant = $this->createTenant();
        $this->recordUsage($tenant, 1200, 'reply');
        $this->recordUsage($tenant, 300, 'summary');
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/settings/ai'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('business/settings/Ai')
            ->where('settings.enabled', true)
            ->where('status.available', true)
            ->where('usage.used', 1500)
            ->where('usage.requests', 2)
            ->where('usage.cap', 300000)
            ->where('usage.features.0.feature', 'reply')
            ->where('usage.features.0.tokens', 1200)
            ->has('tones', 3));

        $this->put($this->appUrl('/settings/ai'), ['enabled' => false, 'auto_extract' => true, 'tone' => 'professional', 'notes' => ' Closed on Tuesdays. '])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(
            ['enabled' => false, 'auto_extract' => true, 'tone' => 'professional', 'notes' => 'Closed on Tuesdays.'],
            $this->inTenant($tenant, fn () => app(AISettings::class)->all()),
        );
        $this->assertTrue(AuditLog::query()->where('action', 'ai.settings_updated')->where('tenant_id', $tenant->id)->exists());
        $this->put($this->appUrl('/settings/ai'), ['enabled' => true, 'auto_extract' => false, 'tone' => 'shouty'])->assertSessionHasErrors('tone');
    }

    public function test_a_manager_can_view_but_not_change_ai_settings(): void
    {
        $tenant = $this->createTenant();
        $manager = User::factory()->create();
        $this->addMember($tenant, $manager, 'manager');
        $this->actingAs($manager);

        $this->get($this->appUrl('/settings/ai'))->assertOk();
        $this->put($this->appUrl('/settings/ai'), ['enabled' => false, 'auto_extract' => false, 'tone' => 'friendly'])->assertForbidden();
    }

    public function test_the_super_admin_sees_usage_per_business_and_sets_a_cap(): void
    {
        $first = $this->createTenant('First Salon');
        $second = $this->createTenant('Second Salon');
        $this->recordUsage($first, 5000, 'reply', ['cost' => 0.002]);
        $this->recordUsage($first, 1000, 'summary', ['status' => 'failed', 'total_tokens' => 0]);
        $this->recordUsage($second, 2000, 'copy');
        $this->recordUsage($second, 9999, 'copy', ['created_at' => now('UTC')->subMonthNoOverflow()->startOfMonth()]);
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin);

        $this->get($this->adminUrl('/ai-usage'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/ai/Usage')
            ->where('totals.requests', 3)
            ->where('totals.tokens', 7000)
            ->where('totals.failed', 1)
            ->where('totals.businesses', 2)
            ->where('tenants.data.0.id', $first->id)
            ->where('tenants.data.0.tokens', 5000)
            ->where('tenants.data.0.failed', 1)
            ->where('tenants.data.0.cap', 300000)
            ->where('tenants.data.0.custom_cap', false)
            ->where('current', true)
            ->where('provider.configured', true));

        $this->get($this->adminUrl('/ai-usage?month='.now('UTC')->subMonthNoOverflow()->format('Y-m')))->assertInertia(fn (Assert $page) => $page
            ->where('totals.tokens', 9999)
            ->where('current', false));
        $this->get($this->adminUrl('/ai-usage?search=Second'))->assertInertia(fn (Assert $page) => $page->has('tenants.data', 1));

        $this->put($this->adminUrl("/tenants/{$second->id}/ai-limit"), ['monthly_tokens' => 1500])->assertSessionHas('success');
        $this->assertSame(1500, app(AIUsageMeter::class)->cap($second));
        $this->assertTrue(app(AIUsageMeter::class)->exceeded($second));
        $this->assertTrue(AuditLog::query()->where('action', 'ai.limit_updated')->where('tenant_id', $second->id)->exists());

        $this->put($this->adminUrl("/tenants/{$second->id}/ai-limit"), ['monthly_tokens' => -1])->assertSessionHasErrors('monthly_tokens');
        $this->put($this->adminUrl("/tenants/{$second->id}/ai-limit"), ['monthly_tokens' => null])->assertSessionHas('success');
        $this->assertFalse(app(AIUsageMeter::class)->hasCustomCap($second));

        $this->get($this->adminUrl('/'))->assertInertia(fn (Assert $page) => $page->where('ai.requests', 3)->where('ai.tokens', 7000));
    }

    public function test_business_users_cannot_reach_the_admin_usage_view_or_write_the_cap(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $this->actingAs($owner);

        $this->get($this->adminUrl('/ai-usage'))->assertForbidden();
        $this->put($this->adminUrl("/tenants/{$tenant->id}/ai-limit"), ['monthly_tokens' => 99999999])->assertForbidden();
        $this->put($this->appUrl('/settings/ai'), ['enabled' => true, 'auto_extract' => true, 'tone' => 'friendly', 'ai_quota' => ['monthly_tokens' => 1]])->assertSessionHasNoErrors();
        $this->assertFalse(TenantSetting::withoutTenantScope()->where('key', AIUsageMeter::QUOTA_KEY)->exists());
    }

    public function test_ai_results_and_usage_stay_inside_their_business(): void
    {
        $first = $this->createTenant('First Salon');
        $second = $this->createTenant('Second Salon');
        $conversation = $this->receive($first, '9876543210', 'Hello from the first salon');
        $draft = $this->inTenant($first, fn () => app(AIService::class)->draftReply($conversation, 'iso:1'));
        $lead = $this->inTenant($first, fn () => Lead::query()->findOrFail($conversation->lead_id));
        $this->actingAs($this->ownerOf($second));

        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/reply"))->assertNotFound();
        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/summary"))->assertNotFound();
        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/drafts/{$draft->id}/dismiss"))->assertNotFound();
        $this->postJson($this->appUrl("/ai/leads/{$lead->id}/summary"))->assertNotFound();
        $this->post($this->appUrl("/ai/leads/{$lead->id}/extract"))->assertNotFound();

        $this->assertSame(AIResult::READY, $draft->refresh()->status);
        $this->assertSame(0, $this->inTenant($second, fn () => AIResult::query()->count()));
        $this->assertSame(1, $this->inTenant($first, fn () => AIResult::query()->count()));
        $this->assertSame(0, $this->inTenant($second, fn () => AIUsage::query()->count()));
    }

    public function test_the_backfill_turns_ai_on_for_existing_businesses_once(): void
    {
        $old = $this->createTenant('Old Salon');
        $optedOut = $this->createTenant('Opted Out Salon');
        $aiModule = Module::query()->where('code', 'ai')->value('id');
        TenantModule::query()->where('tenant_id', $old->id)->where('module_id', $aiModule)->delete();
        TenantSetting::withoutTenantScope()->where('tenant_id', $old->id)->where('key', BackfillTenantModules::KEY)->delete();
        $this->disableModule($optedOut, 'ai');

        $this->seed(TenantBackfillSeeder::class);
        $this->seed(TenantBackfillSeeder::class);

        $enabled = fn ($tenant) => TenantModule::query()->where('tenant_id', $tenant->id)->where('module_id', $aiModule)->where('enabled', true)->exists();
        $this->assertTrue($enabled($old));
        $this->assertFalse($enabled($optedOut));
        $this->assertContains('ai', TenantSetting::withoutTenantScope()->where('tenant_id', $old->id)->where('key', BackfillTenantModules::KEY)->value('value'));
    }
}
