<?php

namespace Tests\Feature\Automation;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Automation\Models\Automation;
use App\Domain\Automation\Services\StepRunner;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AutomationHttpTest extends TestCase
{
    use CreatesAutomations, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    public function test_the_owner_sees_the_default_automations_with_their_steps(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/automations'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/automations/Index')
                ->has('automations', 8)
                ->where('counts.active', 3)
                ->where('automations.0.is_active', true)
                ->has('automations.0.steps')
                ->has('stats'));

        $followup = $this->template($tenant, 'new_lead_followup');

        $this->get($this->appUrl("/automations/{$followup->id}"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/automations/Show')
                ->where('automation.trigger_label', 'Lead created')
                ->where('automation.steps.0.summary', 'Wait 4 hours')
                ->where('automation.steps.1.summary', 'If lead stage is New')
                ->where('automation.steps.2.summary', 'Create follow-up task: Follow up with {{lead.name}}'));
    }

    public function test_the_builder_only_offers_what_the_business_can_use(): void
    {
        $tenant = $this->createTenant('Bright Classes', 'coaching');
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/automations/create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/automations/Create')
                ->where('catalog.triggers', function ($triggers) {
                    $keys = collect($triggers)->pluck('key');

                    return $keys->contains('lead.created') && $keys->filter(fn ($key) => str_starts_with($key, 'appointment.'))->isEmpty();
                })
                ->where('catalog.fields', fn ($fields) => collect($fields)->pluck('key')->doesntContain('appointment.status'))
                ->where('catalog.actions', fn ($actions) => collect($actions)->pluck('key')->contains('send_whatsapp'))
                ->has('catalog.variables'));
    }

    public function test_an_owner_can_create_edit_pause_and_delete_an_automation(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $this->actingAs($owner);

        $definition = [
            'name' => 'Welcome VIP leads',
            'description' => 'High value enquiries get a message and a task.',
            'trigger' => 'lead.created',
            'is_active' => true,
            'steps' => [
                ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'lead.estimated_value', 'operator' => 'gte', 'value' => '5000']]]],
                ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => '  Hi {{lead.first_name}}!  ']],
                ['type' => 'wait', 'config' => ['mode' => 'delay', 'amount' => 1, 'unit' => 'days']],
                ['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'Call {{lead.name}}', 'due_in_hours' => '2']],
            ],
        ];

        $this->post($this->appUrl('/automations'), $definition)->assertSessionHasNoErrors();

        $automation = $this->inTenant($tenant, fn () => Automation::query()->where('name', 'Welcome VIP leads')->sole());
        $this->assertSame($owner->id, $automation->created_by_user_id);
        $this->assertSame([
            ['type' => 'condition', 'action' => null, 'config' => ['match' => 'all', 'rules' => [['field' => 'lead.estimated_value', 'operator' => 'gte', 'value' => 5000]]]],
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hi {{lead.first_name}}!']],
            ['type' => 'wait', 'action' => null, 'config' => ['mode' => 'delay', 'amount' => 1, 'unit' => 'days']],
            ['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'Call {{lead.name}}', 'due_in_hours' => 2]],
        ], $this->normalizeSteps($this->inTenant($tenant, fn () => $automation->load('nodes')->stepDefinitions())));
        $this->assertTrue($this->auditExists($tenant, 'automation.created'));

        $this->get($this->appUrl("/automations/{$automation->id}/edit"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('business/automations/Edit')->where('definition.name', 'Welcome VIP leads')->has('definition.steps', 4));

        $this->put($this->appUrl("/automations/{$automation->id}"), [...$definition, 'name' => 'VIP welcome', 'steps' => [$definition['steps'][1]]])
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->appUrl("/automations/{$automation->id}"));
        $this->assertSame('VIP welcome', $automation->refresh()->name);
        $this->assertSame(1, $this->inTenant($tenant, fn () => $automation->nodes()->count()));

        $this->patch($this->appUrl("/automations/{$automation->id}/toggle"), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($automation->refresh()->is_active);
        $this->assertTrue($this->auditExists($tenant, 'automation.deactivated'));

        $this->delete($this->appUrl("/automations/{$automation->id}"))->assertRedirect($this->appUrl('/automations'));
        $this->assertSoftDeleted($automation);
        $this->get($this->appUrl("/automations/{$automation->id}"))->assertNotFound();
    }

    public function test_invalid_definitions_are_rejected_with_field_errors(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/automations'), [
            'name' => 'Broken',
            'trigger' => 'lead.created',
            'steps' => [
                ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [
                    ['field' => 'appointment.status', 'operator' => 'equals', 'value' => 'confirmed'],
                    ['field' => 'lead.stage', 'operator' => 'gt', 'value' => 'new'],
                    ['field' => 'lead.stage', 'operator' => 'equals', 'value' => 'no-such-stage'],
                ]]],
                ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => '']],
                ['type' => 'action', 'action' => 'update_customer', 'config' => ['tag' => 'vip']],
                ['type' => 'wait', 'config' => ['mode' => 'before_start', 'amount' => 200, 'unit' => 'days']],
            ],
        ])->assertSessionHasErrors([
            'steps.0.config.rules.0.field',
            'steps.0.config.rules.1.operator',
            'steps.0.config.rules.2.value',
            'steps.1.config.message',
            'steps.3.config.mode',
            'steps.3.type',
        ]);

        $this->post($this->appUrl('/automations'), [
            'name' => 'Only waits',
            'trigger' => 'lead.created',
            'steps' => [['type' => 'wait', 'config' => ['mode' => 'delay', 'amount' => 91, 'unit' => 'days']]],
        ])->assertSessionHasErrors(['steps', 'steps.0.config.amount']);

        $this->post($this->appUrl('/automations'), ['name' => 'x', 'trigger' => 'order.shipped', 'steps' => []])
            ->assertSessionHasErrors(['trigger', 'steps']);

        $this->assertSame(8, $this->inTenant($tenant, fn () => Automation::query()->count()));
    }

    public function test_runs_can_be_listed_inspected_cancelled_and_retried(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $followup = $this->template($tenant, 'new_lead_followup');
        $lead = $this->makeLead($tenant, ['name' => 'Priya Sharma']);
        $run = $this->runOf($tenant, $followup);

        $this->get($this->appUrl('/automations/runs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/automations/Runs')
                ->has('runs.data', 1)
                ->where('runs.data.0.subject.label', 'Priya Sharma')
                ->where('runs.data.0.subject.url', "/leads/{$lead->id}")
                ->where('runs.data.0.status', 'waiting'));

        $this->get($this->appUrl("/automations/runs/{$run->id}"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/automations/RunShow')
                ->has('run.steps', 3)
                ->where('run.steps.0.status', 'completed')
                ->where('run.steps.1.status', 'pending')
                ->where('run.steps.2.status', 'not_started')
                ->where('run.logs.2.event', 'wait.scheduled'));

        $this->post($this->appUrl("/automations/runs/{$run->id}/retry"))->assertSessionHasErrors('run');

        $this->post($this->appUrl("/automations/runs/{$run->id}/cancel"))->assertSessionHasNoErrors();
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertContains('run.cancelled', $this->logEvents($tenant, $run));

        $this->post($this->appUrl("/automations/runs/{$run->id}/cancel"))->assertSessionHasErrors('run');
    }

    public function test_a_failed_message_can_be_sent_again(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'send_whatsapp', 'config' => ['message' => 'Hello']],
        ]);
        Queue::fake();
        $this->makeLead($tenant);
        app(StepRunner::class)->run($this->inTenant($tenant, fn () => $this->runOf($tenant, $automation)->jobs()->sole()->id));
        $message = $this->inTenant($tenant, fn () => OutboundMessage::query()->sole());
        $this->inTenant($tenant, fn () => app(MessagingService::class)->markFailed($message, new \RuntimeException('Provider down')));

        $this->post($this->appUrl("/automations/messages/{$message->id}/retry"))->assertSessionHas('success');

        $this->assertSame(MessageStatus::Queued, $message->refresh()->status);
        $this->assertNull($message->error);
        $this->assertTrue($this->auditExists($tenant, 'automation.message_retried'));
        $this->post($this->appUrl("/automations/messages/{$message->id}/retry"))->assertSessionHas('error');
    }

    public function test_permissions_and_the_module_gate(): void
    {
        $tenant = $this->createTenant();
        $staff = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $manager = User::factory()->create();
        $this->addMember($tenant, $manager, 'manager');
        $automation = $this->template($tenant, 'new_lead_followup');

        $this->actingAs($staff);
        $this->get($this->appUrl('/automations'))->assertForbidden();
        $this->get($this->appUrl('/automations/runs'))->assertForbidden();
        $this->patch($this->appUrl("/automations/{$automation->id}/toggle"), ['is_active' => false])->assertForbidden();
        $this->delete($this->appUrl("/automations/{$automation->id}"))->assertForbidden();

        $this->actingAs($manager);
        $this->get($this->appUrl('/automations'))->assertOk();
        $this->patch($this->appUrl("/automations/{$automation->id}/toggle"), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($automation->refresh()->is_active);

        app(ModuleManager::class)->disable($tenant, 'automation');
        $this->get($this->appUrl('/automations'))->assertNotFound();
        $this->get($this->appUrl("/automations/{$automation->id}"))->assertNotFound();
    }

    private function auditExists(Tenant $tenant, string $action): bool
    {
        return AuditLog::query()->where('tenant_id', $tenant->id)->where('action', $action)->exists();
    }

    /** jsonb does not keep key order; put config keys back in the order the builder sends them. */
    private function normalizeSteps(array $steps): array
    {
        $order = ['match', 'rules', 'field', 'operator', 'value', 'message', 'mode', 'amount', 'unit', 'title', 'due_in_hours'];
        $reorder = function (mixed $value) use (&$reorder, $order) {
            if (! is_array($value)) {
                return $value;
            }

            $value = array_map($reorder, $value);

            if (! array_is_list($value)) {
                uksort($value, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
            }

            return $value;
        };

        return array_map(fn (array $step) => ['type' => $step['type'], 'action' => $step['action'], 'config' => $reorder($step['config'])], $steps);
    }
}
