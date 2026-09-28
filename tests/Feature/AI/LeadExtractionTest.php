<?php

namespace Tests\Feature\AI;

use App\Domain\Activity\Models\Activity;
use App\Domain\AI\Actions\ExtractLeadDetails;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Jobs\ExtractLeadFromConversation;
use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Models\AIUsage;
use App\Domain\AI\Services\AIService;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAi;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Lead details from what a contact wrote: empty fields filled, the rest suggested (ADR-019). */
class LeadExtractionTest extends TestCase
{
    use CreatesAi, CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_empty_fields_and_a_placeholder_name_are_filled_and_shown_on_the_timeline(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'Hi, my name is Riya Sharma. Mail me at Riya@Example.com, budget Rs 5,000 for bridal makeup.');
        $lead = $this->inTenant($tenant, fn () => Lead::query()->findOrFail($conversation->lead_id));
        $this->assertTrue(ExtractLeadDetails::isPlaceholderName($lead));
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl("/ai/leads/{$lead->id}/extract"))->assertRedirect()->assertSessionHas('success', fn ($message) => str_starts_with($message, 'Filled in:'));

        $lead->refresh();
        $this->assertSame(['Riya Sharma', 'riya@example.com', 5000.0], [$lead->name, $lead->email, (float) $lead->estimated_value]);
        $result = AIResult::withoutTenantScope()->where('feature', AIService::EXTRACTION)->sole();
        $this->assertSame([AIResult::USED, 'manual', true], [$result->status, $result->output['via'], $result->output['used_ai']]);
        $activity = $this->inTenant($tenant, fn () => Activity::query()->where('lead_id', $lead->id)->where('type', 'updated')->latest('id')->first());
        $this->assertSame('ai', $activity->metadata['via']);
        $this->assertEqualsCanonicalizing(['name', 'email', 'estimated_value'], $activity->metadata['changed']);
    }

    public function test_differing_values_become_suggestions_that_can_be_applied_or_dismissed(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $lead = $this->makeLead($tenant, ['name' => 'Priya', 'phone' => '9876543210', 'email' => 'old@example.com'], $owner);
        $this->receive($tenant, '9876543210', 'Hello, this is Priya Nair, my new email is priya.nair@example.com');
        $this->actingAs($owner);

        $this->post($this->appUrl("/ai/leads/{$lead->id}/extract"))->assertSessionHas('success', 'AI found details that differ from the lead. Review them below.');
        $result = AIResult::withoutTenantScope()->where('feature', AIService::EXTRACTION)->sole();
        $this->assertSame(AIResult::READY, $result->status);

        $this->get($this->appUrl("/leads/{$lead->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('ai.suggestions.id', $result->id)
            ->has('ai.suggestions.suggestions', 2)
            ->where('ai.suggestions.suggestions.0.attribute', 'name')
            ->where('ai.suggestions.suggestions.0.value', 'Priya Nair')
            ->where('ai.suggestions.suggestions.0.current', 'Priya'));

        $this->post($this->appUrl("/ai/leads/{$lead->id}/suggestions/apply"), ['result_id' => $result->id, 'attributes' => ['name']])->assertSessionHas('success', 'Lead updated.');
        $this->assertSame(['Priya Nair', 'old@example.com'], [$lead->refresh()->name, $lead->email]);
        $this->assertSame(AIResult::READY, $result->refresh()->status);
        $this->assertSame('ai_suggestion', $this->inTenant($tenant, fn () => Activity::query()->where('lead_id', $lead->id)->where('type', 'updated')->latest('id')->value('metadata'))['via']);

        $this->post($this->appUrl("/ai/leads/{$lead->id}/suggestions/dismiss"), ['result_id' => $result->id, 'attributes' => ['email']])->assertSessionHasNoErrors();
        $this->assertSame(AIResult::USED, $result->refresh()->status);
        $this->assertSame('old@example.com', $lead->refresh()->email);
        $this->get($this->appUrl("/leads/{$lead->id}"))->assertInertia(fn (Assert $page) => $page->where('ai.suggestions', null));

        $this->post($this->appUrl("/ai/leads/{$lead->id}/suggestions/apply"), ['result_id' => $result->id, 'attributes' => ['phone']])->assertSessionHasErrors('attributes.0');
    }

    public function test_the_same_messages_are_read_only_once_and_short_text_is_never_sent_to_ai(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'a@b.co');
        $lead = $this->inTenant($tenant, fn () => Lead::query()->findOrFail($conversation->lead_id));

        $first = $this->inTenant($tenant, fn () => app(ExtractLeadDetails::class)->handle($lead));
        $again = $this->inTenant($tenant, fn () => app(ExtractLeadDetails::class)->handle($lead->refresh()));

        $this->assertSame($first->id, $again->id);
        $this->assertFalse($first->output['used_ai']);
        $this->assertSame('a@b.co', $lead->refresh()->email);
        $this->assertSame(0, AIUsage::withoutTenantScope()->count());
    }

    public function test_a_lead_that_has_written_nothing_is_not_read(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $lead = $this->makeLead($tenant, [], $owner);
        $this->actingAs($owner);

        $this->post($this->appUrl("/ai/leads/{$lead->id}/extract"))->assertSessionHas('error');
        $this->assertSame(0, AIResult::withoutTenantScope()->count());
    }

    public function test_only_users_who_can_update_leads_can_extract_or_apply(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'Hi, my name is Riya Sharma and I want a facial.');
        $sales = User::factory()->create();
        $staff = User::factory()->create();
        $this->addMember($tenant, $sales, 'sales_executive');
        $this->addMember($tenant, $staff, 'staff');

        $this->actingAs($staff)->post($this->appUrl("/ai/leads/{$conversation->lead_id}/extract"))->assertForbidden();
        $this->actingAs($sales)->post($this->appUrl("/ai/leads/{$conversation->lead_id}/extract"))->assertSessionHas('success');
    }

    public function test_the_listener_queues_one_delayed_extraction_for_text_from_a_lead(): void
    {
        Queue::fake();
        $tenant = $this->createTenant();

        $this->receive($tenant, '9876543210', 'Hi, I want a haircut');
        Queue::assertNotPushed(ExtractLeadFromConversation::class);

        $this->setAiSettings($tenant, ['auto_extract' => true]);
        $conversation = $this->receive($tenant, '9876543210', 'Hi, I want a haircut');
        $this->receive($tenant, '9876543210', 'STOP');

        Queue::assertPushed(ExtractLeadFromConversation::class, 1);
        Queue::assertPushed(ExtractLeadFromConversation::class, fn (ExtractLeadFromConversation $job) => $job->tenantId === $tenant->id
            && $job->conversationId === $conversation->id
            && $job->queue === 'ai'
            && $job->delay !== null);
    }

    public function test_the_auto_extraction_fills_the_lead_and_stops_after_the_run_limit(): void
    {
        config(['ai.extraction.max_auto_runs' => 1]);
        $tenant = $this->createTenant();
        $this->setAiSettings($tenant, ['auto_extract' => true]);

        $conversation = $this->receive($tenant, '9876543210', 'Hello, my name is Kavya Iyer, looking for a hair spa');
        $this->receive($tenant, '9876543210', 'My email is kavya@example.com');

        $lead = $this->inTenant($tenant, fn () => Lead::query()->findOrFail($conversation->lead_id));
        $this->assertSame('Kavya Iyer', $lead->name);
        $this->assertNull($lead->email);
        $this->assertSame(1, AIResult::withoutTenantScope()->where('feature', AIService::EXTRACTION)->where('output->via', 'auto')->count());
    }

    public function test_the_auto_extraction_does_nothing_when_ai_is_switched_off(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'Hello, my name is Kavya Iyer, looking for a hair spa');
        $this->setAiSettings($tenant, ['auto_extract' => true, 'enabled' => false]);

        (new ExtractLeadFromConversation($tenant->id, $conversation->id))->handle(app(TenantContext::class));

        $this->assertSame(0, AIResult::withoutTenantScope()->count());
    }

    public function test_a_permanent_provider_error_ends_the_job_and_a_temporary_one_retries(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::sequence()
            ->push(['error' => ['message' => 'bad request']], 400)
            ->push(['error' => ['message' => 'overloaded']], 503)]);
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'Hello, my name is Kavya Iyer, looking for a hair spa');
        $this->setAiSettings($tenant, ['auto_extract' => true]);
        $job = new ExtractLeadFromConversation($tenant->id, $conversation->id);
        $context = app(TenantContext::class);

        $job->handle($context);

        $this->expectException(AIProviderException::class);
        $job->handle($context);
    }
}
