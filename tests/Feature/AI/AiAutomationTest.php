<?php

namespace Tests\Feature\AI;

use App\Domain\Activity\Models\Activity;
use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Services\AIService;
use App\Domain\Automation\Enums\RunStatus;
use App\Domain\Lead\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAi;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** The "message received" trigger and the AI actions (AW-053, ADR-019). */
class AiAutomationTest extends TestCase
{
    use CreatesAi, CreatesAutomations, CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_a_received_message_drafts_a_reply_for_a_person_to_send(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'message.received', [
            ['type' => 'action', 'action' => 'ai_draft_reply', 'config' => ['instructions' => 'Offer a call back']],
        ]);

        $conversation = $this->receive($tenant, '9876543210', 'What is the price of a facial?');

        $run = $this->runOf($tenant, $automation);
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame('conversation', $run->subject_type);
        $this->assertContains('action.completed', $this->logEvents($tenant, $run));
        $draft = AIResult::withoutTenantScope()->where('feature', AIService::REPLY_DRAFT)->sole();
        $this->assertSame([$conversation->id, AIResult::READY], [$draft->subject_id, $draft->status]);
        $this->assertSame('inbound', $conversation->refresh()->last_message_direction);
    }

    public function test_opt_out_keywords_do_not_start_automations_and_conditions_can_read_the_message(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'message.received', [
            ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [
                ['field' => 'message.text', 'operator' => 'contains', 'value' => 'price'],
                ['field' => 'conversation.channel', 'operator' => 'equals', 'value' => 'whatsapp'],
            ]]],
            ['type' => 'action', 'action' => 'ai_draft_reply', 'config' => []],
        ]);

        $this->receive($tenant, '9876543210', 'STOP');
        $this->assertCount(0, $this->runsOf($tenant, $automation));

        $this->receive($tenant, '9876543211', 'Hello');
        $this->receive($tenant, '9876543212', 'Price list please');

        $runs = $this->runsOf($tenant, $automation);
        $this->assertSame([RunStatus::Skipped, RunStatus::Completed], $runs->pluck('status')->all());
        $this->assertSame(1, AIResult::withoutTenantScope()->where('feature', AIService::REPLY_DRAFT)->count());
    }

    public function test_no_draft_is_made_once_someone_has_replied_or_the_contact_opted_out(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $conversation = $this->receive($tenant, '9876543210', 'Hello there');
        $automation = $this->makeAutomation($tenant, 'message.received', [
            ['type' => 'action', 'action' => 'ai_draft_reply', 'config' => []],
        ]);
        $this->inTenant($tenant, fn () => $conversation->forceFill(['opted_out_at' => now()])->save());

        $this->receive($tenant, '9876543210', 'Are you there?');

        $this->assertContains('action.skipped', $this->logEvents($tenant, $this->runOf($tenant, $automation)));
        $this->assertSame(0, AIResult::withoutTenantScope()->count());
    }

    public function test_the_extract_action_fills_the_lead_from_the_conversation(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $this->makeAutomation($tenant, 'message.received', [
            ['type' => 'action', 'action' => 'ai_extract_lead', 'config' => []],
        ]);

        $conversation = $this->receive($tenant, '9876543210', 'Hello, my name is Kavya Iyer and my email is kavya@example.com');

        $lead = $this->inTenant($tenant, fn () => Lead::query()->findOrFail($conversation->lead_id));
        $this->assertSame(['Kavya Iyer', 'kavya@example.com'], [$lead->name, $lead->email]);
        $this->assertSame('automation', AIResult::withoutTenantScope()->where('feature', AIService::EXTRACTION)->sole()->output['via']);
    }

    public function test_the_summary_action_adds_one_note_per_run(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'ai_summarize', 'config' => []],
        ]);

        $lead = $this->makeLead($tenant, ['name' => 'Asha Rao', 'interest' => 'Hair spa']);

        $note = $this->inTenant($tenant, fn () => Activity::query()->where('lead_id', $lead->id)->where('type', 'note')->sole());
        $this->assertStringStartsWith('AI summary: ', $note->body);
        $this->assertSame(['ai', $automation->id], [$note->metadata['via'], $note->metadata['automation_id']]);
    }

    public function test_ai_actions_are_skipped_when_ai_is_unavailable(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'lead.created', [
            ['type' => 'action', 'action' => 'ai_summarize', 'config' => []],
        ]);
        $this->recordUsage($tenant, 300000);

        $this->makeLead($tenant);

        $this->assertContains('action.skipped', $this->logEvents($tenant, $this->runOf($tenant, $automation)));
        $this->assertSame(0, Activity::withoutTenantScope()->where('type', 'note')->count());
    }

    public function test_ai_actions_need_the_ai_module(): void
    {
        $tenant = $this->createTenant();
        $this->disableModule($tenant, 'ai');
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/automations'), [
            'name' => 'Draft replies',
            'trigger' => 'message.received',
            'is_active' => true,
            'steps' => [['type' => 'action', 'action' => 'ai_draft_reply', 'config' => []]],
        ])->assertSessionHasErrors();
    }
}
