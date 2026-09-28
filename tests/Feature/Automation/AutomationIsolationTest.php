<?php

namespace Tests\Feature\Automation;

use App\Domain\Activity\Models\Activity;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\AutomationResolver;
use App\Domain\Automation\Services\StepDispatcher;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Tenant\Exceptions\CrossTenantWrite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Mandatory cross-tenant tests (master prompt §86) for automations, runs and messages.
 */
class AutomationIsolationTest extends TestCase
{
    use CreatesAutomations, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_an_event_only_starts_automations_of_its_own_tenant(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        $this->makeLead($b);

        $this->assertSame(0, $this->inTenant($a, fn () => AutomationRun::query()->count()));
        $this->assertSame(1, $this->inTenant($b, fn () => AutomationRun::query()->count()));
        $this->assertSame(0, AutomationRun::withoutTenantScope()->where('tenant_id', $a->id)->count());

        // Starting from tenant A's context still runs in the lead's own tenant.
        $theirLead = $this->makeLead($b);
        $this->inTenant($a, fn () => app(AutomationResolver::class)->start('lead.created', $theirLead, 'lead:'.$theirLead->id.':again'));
        $this->assertSame(0, AutomationRun::withoutTenantScope()->where('tenant_id', $a->id)->count());
    }

    public function test_tenant_a_cannot_see_or_change_tenant_b_automations_runs_or_messages(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $this->pauseDefaultAutomations($b);
        $theirs = $this->makeAutomation($b, 'lead.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Secret offer']],
        ], ['name' => 'Their automation']);
        $this->makeLead($b);
        $run = $this->runOf($b, $theirs);
        $message = $this->inTenant($b, fn () => OutboundMessage::query()->sole());

        $this->actingAs($this->ownerOf($a));

        $this->get($this->appUrl("/automations/{$theirs->id}"))->assertNotFound();
        $this->get($this->appUrl("/automations/{$theirs->id}/edit"))->assertNotFound();
        $this->put($this->appUrl("/automations/{$theirs->id}"), ['name' => 'Hijacked', 'trigger' => 'lead.created', 'steps' => []])->assertNotFound();
        $this->patch($this->appUrl("/automations/{$theirs->id}/toggle"), ['is_active' => false])->assertNotFound();
        $this->delete($this->appUrl("/automations/{$theirs->id}"))->assertNotFound();
        $this->get($this->appUrl("/automations/runs/{$run->id}"))->assertNotFound();
        $this->post($this->appUrl("/automations/runs/{$run->id}/cancel"))->assertNotFound();
        $this->post($this->appUrl("/automations/runs/{$run->id}/retry"))->assertNotFound();
        $this->post($this->appUrl("/automations/messages/{$message->id}/retry"))->assertNotFound();

        $this->get($this->appUrl('/automations'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('automations', fn ($list) => collect($list)->pluck('name')->doesntContain('Their automation')));
        $this->get($this->appUrl('/automations/runs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('runs.data', 0)->where('automations', fn ($list) => collect($list)->pluck('name')->doesntContain('Their automation')));

        $fresh = Automation::withoutTenantScope()->findOrFail($theirs->id);
        $this->assertSame('Their automation', $fresh->name);
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_a_run_cannot_be_written_into_another_tenant(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $theirs = $this->template($b, 'new_lead_followup');

        $this->expectException(CrossTenantWrite::class);

        $this->inTenant($a, fn () => AutomationRun::query()->create([
            'tenant_id' => $b->id,
            'automation_id' => $theirs->id,
            'trigger' => 'lead.created',
            'subject_type' => 'lead',
            'subject_id' => 1,
            'dedupe_key' => 'x',
            'status' => 'pending',
            'steps' => [],
        ]));
    }

    public function test_a_step_cannot_act_on_a_record_from_another_tenant(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');
        $ours = $this->template($a, 'new_lead_followup');
        $theirLead = $this->makeLead($b);

        // A run whose subject id points at another tenant's lead (e.g. forged data) finds no subject.
        $run = $this->inTenant($a, fn () => AutomationRun::query()->create([
            'automation_id' => $ours->id,
            'trigger' => 'lead.created',
            'subject_type' => 'lead',
            'subject_id' => $theirLead->id,
            'dedupe_key' => 'forged',
            'status' => 'pending',
            'steps' => [['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'x', 'due_in_hours' => 0]]],
        ]));
        $this->inTenant($a, fn () => app(StepDispatcher::class)->schedule($run, 0, now()));

        $this->assertSame('cancelled', $run->refresh()->status->value);
        $this->assertSame(0, $this->inTenant($b, fn () => Activity::query()->where('type', 'task')->count()));
    }
}
