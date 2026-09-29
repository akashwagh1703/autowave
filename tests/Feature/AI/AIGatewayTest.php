<?php

namespace Tests\Feature\AI;

use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIUnavailable;
use App\Domain\AI\Models\AIUsage;
use App\Domain\AI\Services\AIGateway;
use App\Domain\AI\Services\AIService;
use App\Domain\AI\Support\AIUsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAi;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesMessaging;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** The provider, availability checks, metering and the monthly cap (ADR-019). */
class AIGatewayTest extends TestCase
{
    use CreatesAi, CreatesCrmRecords, CreatesMessaging, CreatesTenants, RefreshDatabase;

    public function test_openrouter_is_called_with_the_key_in_the_header_and_usage_is_metered(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::response($this->completion('"Reply: Yes, we are open till 8 pm."', prompt: 120, completion: 30, cost: 0.0012))]);
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);
        $conversation = $this->receive($tenant, '9876543210', 'Are you open today evening?');

        $text = $this->inTenant($tenant, fn () => app(AIService::class)->suggestReply($conversation, null, $owner));

        $this->assertSame('Yes, we are open till 8 pm.', $text);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer '.self::OPENROUTER_KEY)
                && $request['model'] === 'openai/gpt-4o-mini'
                && $request['max_tokens'] === 350
                && $request['usage'] === ['include' => true]
                && ! isset($request['reasoning'])
                && str_contains($request['messages'][1]['content'], 'Are you open today evening?')
                && ! str_contains(json_encode($request->data()), self::OPENROUTER_KEY);
        });

        $usage = AIUsage::withoutTenantScope()->sole();
        $this->assertSame([$tenant->id, $owner->id, 'reply', 'openrouter', AIUsage::SUCCEEDED, 120, 30, 150], [
            $usage->tenant_id, $usage->user_id, $usage->feature, $usage->provider, $usage->status, $usage->prompt_tokens, $usage->completion_tokens, $usage->total_tokens,
        ]);
        $this->assertEquals(0.0012, (float) $usage->cost);
    }

    public function test_tool_calls_are_parsed(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::response($this->completion(null, [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'leads', 'arguments' => '{"stage":"new"}']],
            ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'business_overview', 'arguments' => 'not json']],
        ]))]);
        $tenant = $this->createTenant();

        $response = $this->inTenant($tenant, fn () => app(AIGateway::class)->chat(ChatRequest::for('assistant', [['role' => 'user', 'content' => 'Hi']])));

        $this->assertSame([
            ['id' => 'call_1', 'name' => 'leads', 'arguments' => ['stage' => 'new']],
            ['id' => 'call_2', 'name' => 'business_overview', 'arguments' => []],
        ], $response->toolCalls);
    }

    public function test_reasoning_can_be_switched_off_or_limited(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::response($this->completion('"Hi"'))]);
        $tenant = $this->createTenant();
        $chat = fn () => $this->inTenant($tenant, fn () => app(AIGateway::class)->chat(ChatRequest::for('copy', [['role' => 'user', 'content' => 'Hi']])));

        config(['ai.openrouter.reasoning' => 'off']);
        $chat();
        Http::assertSent(fn (Request $request) => $request['reasoning'] === ['enabled' => false]);

        config(['ai.openrouter.reasoning' => ' Low ']);
        $chat();
        Http::assertSent(fn (Request $request) => $request['reasoning'] === ['effort' => 'low']);

        config(['ai.openrouter.reasoning' => 'maximum']);
        $chat();
        $this->assertFalse(isset(Http::recorded()->last()[0]['reasoning']));
    }

    public function test_provider_errors_are_classified_and_metered_as_failed(): void
    {
        $this->useOpenRouter(['openrouter.ai/*' => Http::sequence()
            ->push(['error' => ['message' => 'Rate limited']], 429)
            ->push(['error' => ['message' => 'Invalid model']], 400)
            ->push(['error' => ['message' => 'Upstream failed']], 200)]);
        $tenant = $this->createTenant();
        $request = ChatRequest::for('summary', [['role' => 'user', 'content' => 'Hi']]);
        $errors = [];

        foreach (range(1, 3) as $attempt) {
            try {
                $this->inTenant($tenant, fn () => app(AIGateway::class)->chat($request));
            } catch (AIProviderException $exception) {
                $errors[] = [$exception->temporary, $exception->status];
            }
        }

        $this->assertSame([[true, 429], [false, 400], [true, null]], $errors);
        $this->assertSame(3, AIUsage::withoutTenantScope()->where('status', AIUsage::FAILED)->count());
        $this->assertSame(0, (int) AIUsage::withoutTenantScope()->sum('total_tokens'));
        $this->assertStringNotContainsString(self::OPENROUTER_KEY, (string) AIUsage::withoutTenantScope()->pluck('error')->implode(' '));
    }

    public function test_availability_is_checked_in_order_module_switch_key_and_cap(): void
    {
        $tenant = $this->createTenant();
        $reason = fn () => $this->inTenant($tenant, fn () => app(AIGateway::class)->status()['reason']);

        $this->assertNull($reason());

        $this->recordUsage($tenant, 300000);
        $this->assertSame(AIUnavailable::LIMIT, $reason());

        config(['ai.provider' => 'openrouter', 'ai.openrouter.api_key' => null]);
        $this->assertSame(AIUnavailable::NOT_CONFIGURED, $reason());

        $this->setAiSettings($tenant, ['enabled' => false]);
        $this->assertSame(AIUnavailable::DISABLED, $reason());

        $this->disableModule($tenant, 'ai');
        $this->assertSame(AIUnavailable::MODULE, $reason());
    }

    public function test_an_unavailable_gateway_makes_no_call_and_records_nothing(): void
    {
        $this->useOpenRouter(['*' => Http::response($this->completion('Hi'))]);
        $tenant = $this->createTenant();
        $this->setAiSettings($tenant, ['enabled' => false]);

        try {
            $this->inTenant($tenant, fn () => app(AIGateway::class)->chat(ChatRequest::for('reply', [['role' => 'user', 'content' => 'Hi']])));
            $this->fail('Expected AIUnavailable.');
        } catch (AIUnavailable $exception) {
            $this->assertSame(AIUnavailable::DISABLED, $exception->reason);
        }

        Http::assertNothingSent();
        $this->assertSame(0, AIUsage::withoutTenantScope()->count());
    }

    public function test_the_cap_counts_only_this_month_and_can_be_overridden_per_business(): void
    {
        $tenant = $this->createTenant();
        $meter = app(AIUsageMeter::class);
        $this->recordUsage($tenant, 500000, attributes: ['created_at' => now('UTC')->startOfMonth()->subDay()]);
        $this->recordUsage($tenant, 1000);

        $this->assertSame(1000, $meter->used($tenant));
        $this->assertFalse($meter->exceeded($tenant));

        $meter->setCap($tenant, 1000);
        $this->assertSame(1000, $meter->cap($tenant));
        $this->assertTrue($meter->hasCustomCap($tenant));
        $this->assertTrue($meter->exceeded($tenant));

        $meter->setCap($tenant, null);
        $this->assertSame(300000, $meter->cap($tenant));
        $this->assertFalse($meter->hasCustomCap($tenant));
        $this->assertSame(['used' => 1000, 'cap' => 300000, 'requests' => 1, 'percent' => 0], array_intersect_key($meter->summary($tenant), array_flip(['used', 'cap', 'requests', 'percent'])));
    }

    public function test_usage_of_one_business_does_not_count_against_another(): void
    {
        $first = $this->createTenant('First Salon');
        $second = $this->createTenant('Second Salon');
        $this->recordUsage($first, 300000);

        $this->assertFalse($this->inTenant($first, fn () => app(AIGateway::class)->available()));
        $this->assertTrue($this->inTenant($second, fn () => app(AIGateway::class)->available()));
    }
}
