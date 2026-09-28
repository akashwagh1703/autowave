<?php

namespace Tests\Feature\AI;

use App\Domain\AI\Models\AIResult;
use App\Domain\AI\Models\AIUsage;
use App\Domain\AI\Providers\FakeProvider;
use App\Domain\AI\Services\AIService;
use App\Domain\Customer\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesAi;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** AI helpers over HTTP: reply drafts, summaries, writing help, permissions and limits (ADR-019). */
class AiHttpTest extends TestCase
{
    use CreatesAi, CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_a_reply_suggestion_is_returned_and_never_sent(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'Do you do bridal makeup?');
        $this->actingAs($this->ownerOf($tenant));

        $text = $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/reply"))->assertOk()->json('text');
        $this->assertStringContainsString(FakeProvider::NOTE, $text);

        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/reply"), ['draft' => 'yes we do', 'instructions' => 'Mention the price list'])->assertOk();
        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/reply"), ['instructions' => str_repeat('a', 501)])->assertUnprocessable();

        $this->assertSame('inbound', $conversation->refresh()->last_message_direction);
        $this->assertSame(0, $this->inTenant($tenant, fn () => $conversation->messages()->where('direction', 'outbound')->count()));
        $this->assertSame(2, AIUsage::withoutTenantScope()->where('feature', 'reply')->count());
    }

    public function test_a_conversation_summary_is_reused_until_a_new_message_arrives(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'I want a haircut on Saturday');
        $this->actingAs($this->ownerOf($tenant));
        $url = $this->appUrl("/ai/conversations/{$conversation->id}/summary");

        $first = $this->postJson($url)->assertOk()->json('summary');
        $this->assertStringContainsString('haircut on Saturday', $first['text']);
        $this->assertSame($first['id'], $this->postJson($url)->json('summary.id'));
        $this->assertSame(1, AIUsage::withoutTenantScope()->count());

        $this->postJson($url, ['refresh' => true])->assertOk();
        $this->assertSame(2, AIUsage::withoutTenantScope()->count());

        $this->receive($tenant, '9876543210', 'Around 5 pm please');
        $this->assertNotSame($first['id'], $this->postJson($url)->json('summary.id'));
        $this->assertSame(3, AIUsage::withoutTenantScope()->count());
    }

    public function test_lead_and_customer_summaries_come_from_the_timeline_and_show_on_their_pages(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $lead = $this->makeLead($tenant, ['name' => 'Asha Rao', 'interest' => 'Hair spa'], $owner);
        $customer = $this->makeCustomer($tenant, ['name' => 'Meera Shah'], $owner);
        $this->actingAs($owner);

        $summary = $this->postJson($this->appUrl("/ai/leads/{$lead->id}/summary"))->assertOk()->json('summary');
        $this->assertStringContainsString(FakeProvider::NOTE, $summary['text']);
        $this->postJson($this->appUrl("/ai/customers/{$customer->id}/summary"))->assertOk();

        $this->get($this->appUrl("/leads/{$lead->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('ai.summary.id', $summary['id'])
            ->where('ai.suggestions', null)
            ->where('ai.can_extract', true));
        $this->get($this->appUrl("/customers/{$customer->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('ai.summary.text', fn ($text) => str_contains($text, FakeProvider::NOTE)));
    }

    public function test_a_record_with_nothing_to_summarise_costs_no_ai_call(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $customer = $this->inTenant($tenant, fn () => Customer::query()->create(['name' => 'Quiet Customer']));

        $this->postJson($this->appUrl("/ai/customers/{$customer->id}/summary"))->assertOk()->assertJsonPath('summary.text', 'Nothing to summarise yet.');
        $this->assertSame(0, AIUsage::withoutTenantScope()->count());
    }

    public function test_writing_help_checks_the_permission_of_its_kind(): void
    {
        $tenant = $this->createTenant();
        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');

        $this->actingAs($this->ownerOf($tenant));
        $this->postJson($this->appUrl('/ai/write'), [
            'kind' => 'website_field',
            'instructions' => 'Mention free parking',
            'context' => ['section' => 'About', 'field' => 'Body', 'current' => 'We are a salon.'],
        ])->assertOk()->assertJsonPath('text', fn ($text) => str_contains($text, 'Mention free parking'));
        $this->postJson($this->appUrl('/ai/write'), ['kind' => 'automation_message', 'context' => ['placeholders' => ['lead.first_name', 'Bad Key']]])->assertUnprocessable();
        $this->postJson($this->appUrl('/ai/write'), ['kind' => 'unknown'])->assertUnprocessable();

        $this->actingAs($receptionist);
        $this->postJson($this->appUrl('/ai/write'), ['kind' => 'website_field'])->assertForbidden();
        $this->postJson($this->appUrl('/ai/write'), ['kind' => 'automation_message'])->assertForbidden();
        $this->postJson($this->appUrl('/ai/write'), ['kind' => 'offer', 'instructions' => 'Diwali 20% off'])->assertOk();
    }

    public function test_generated_copy_respects_the_kind_length_limit(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::response($this->completion(str_repeat('word ', 400)))]);
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $text = $this->postJson($this->appUrl('/ai/write'), ['kind' => 'offer'])->assertOk()->json('text');

        $this->assertLessThanOrEqual(600, mb_strlen($text));
    }

    public function test_permissions_staff_cannot_use_ai_and_receptionists_cannot_use_the_assistant(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'Hello there');
        $staff = User::factory()->create();
        $receptionist = User::factory()->create();
        $this->addMember($tenant, $staff, 'staff');
        $this->addMember($tenant, $receptionist, 'receptionist');

        $this->actingAs($staff);
        $this->get($this->appUrl('/assistant'))->assertForbidden();
        $this->postJson($this->appUrl('/ai/write'), ['kind' => 'offer'])->assertForbidden();
        $this->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page->where('ai', null));

        $this->actingAs($receptionist);
        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/reply"))->assertOk();
        $this->get($this->appUrl('/assistant'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canAsk', false)->where('examples', []));
        $this->postJson($this->appUrl('/assistant/ask'), ['messages' => [['role' => 'user', 'content' => 'How many leads?']]])->assertForbidden();
        $this->get($this->appUrl('/settings/ai'))->assertForbidden();
    }

    public function test_ai_routes_are_hidden_when_the_module_is_off(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'Hello there');
        $this->disableModule($tenant, 'ai');
        $this->actingAs($this->ownerOf($tenant));

        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/reply"))->assertNotFound();
        $this->get($this->appUrl('/assistant'))->assertNotFound();
        $this->get($this->appUrl('/settings/ai'))->assertNotFound();
        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('ai', null)
            ->where('conversation.ai_draft', null));
    }

    public function test_an_unavailable_ai_answers_409_with_the_reason_and_a_provider_failure_503(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->receive($tenant, '9876543210', 'Hello there');
        $this->actingAs($this->ownerOf($tenant));
        $this->recordUsage($tenant, 300000);

        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/reply"))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'limit');
        $this->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('ai.available', false)
            ->where('ai.reason', 'limit'));

        AIUsage::withoutTenantScope()->delete();
        Cache::flush();
        $this->useOpenRouter(['openrouter.ai/*' => Http::response(['error' => ['message' => 'down']], 502)]);

        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/reply"))
            ->assertStatus(503)
            ->assertJsonPath('reason', 'provider')
            ->assertJsonMissingPath('error');
    }

    public function test_the_shared_ai_prop_never_exposes_provider_details(): void
    {
        $this->useOpenRouter();
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $response = $this->get($this->appUrl('/dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('ai', ['available' => true, 'reason' => null, 'message' => null]));

        $this->assertStringNotContainsString(self::OPENROUTER_KEY, $response->getContent());
        $this->assertStringNotContainsString('openrouter.ai', $response->getContent());
    }

    public function test_ai_requests_are_rate_limited_per_user(): void
    {
        config(['ai.limits.per_minute' => 2]);
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->postJson($this->appUrl('/ai/write'), ['kind' => 'offer'])->assertOk();
        $this->postJson($this->appUrl('/ai/write'), ['kind' => 'offer'])->assertOk();
        $this->postJson($this->appUrl('/ai/write'), ['kind' => 'offer'])->assertTooManyRequests();
    }

    public function test_an_automation_draft_shows_in_the_inbox_until_someone_replies_or_dismisses_it(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $conversation = $this->receive($tenant, '9876543210', 'What are your timings?');
        $draft = $this->inTenant($tenant, fn () => app(AIService::class)->draftReply($conversation, 'test:1'));
        $this->assertSame($draft->id, $this->inTenant($tenant, fn () => app(AIService::class)->draftReply($conversation, 'test:1'))->id);
        $this->actingAs($owner);

        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertInertia(fn (Assert $page) => $page
            ->where('conversation.ai_draft.id', $draft->id)
            ->where('conversation.ai_draft.text', fn ($text) => str_contains($text, FakeProvider::NOTE)));

        $this->postJson($this->appUrl("/ai/conversations/{$conversation->id}/drafts/{$draft->id}/dismiss"))->assertOk();
        $this->assertSame(AIResult::DISMISSED, $draft->refresh()->status);
        $this->get($this->appUrl("/inbox/{$conversation->id}"))->assertInertia(fn (Assert $page) => $page->where('conversation.ai_draft', null));

        $second = $this->inTenant($tenant, fn () => app(AIService::class)->draftReply($conversation, 'test:2'));
        $this->assertSame($second->id, $this->inTenant($tenant, fn () => AIService::pendingDraft($conversation)?->id));
        $this->post($this->appUrl("/inbox/{$conversation->id}/messages"), ['body' => 'We are open 10 to 8.'])->assertSessionHasNoErrors();
        $this->assertNull($this->inTenant($tenant, fn () => AIService::pendingDraft($conversation)));
    }
}
