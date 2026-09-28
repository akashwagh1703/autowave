<?php

namespace Tests\Concerns;

use App\Domain\AI\Models\AIUsage;
use App\Domain\AI\Support\AISettings;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** AI fixtures (ADR-019). Use with CreatesCrmRecords and CreatesTenants. */
trait CreatesAi
{
    protected const OPENROUTER_KEY = 'sk-or-test-key';

    /** Switch to OpenRouter with a test key; no request may leave the test except the faked ones. */
    protected function useOpenRouter(array $responses = []): void
    {
        config(['ai.provider' => 'openrouter', 'ai.openrouter.api_key' => self::OPENROUTER_KEY]);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    /** An OpenRouter chat completion body. */
    protected function completion(?string $content, array $toolCalls = [], int $prompt = 100, int $completion = 20, ?float $cost = 0.0004): array
    {
        return [
            'id' => 'gen-test',
            'model' => 'openai/gpt-4o-mini',
            'choices' => [['message' => array_filter([
                'role' => 'assistant',
                'content' => $content,
                'tool_calls' => $toolCalls !== [] ? $toolCalls : null,
            ], fn ($value) => $value !== null)]],
            'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion, 'cost' => $cost],
        ];
    }

    /** @param  array<string, mixed>  $values */
    protected function setAiSettings(Tenant $tenant, array $values): void
    {
        $this->inTenant($tenant, fn () => TenantSetting::query()->updateOrCreate(
            ['key' => AISettings::KEY],
            ['value' => [...config('ai.defaults'), ...$values]],
        ));
    }

    /** Usage written directly, as if from earlier calls (clears the meter's cached total). */
    protected function recordUsage(Tenant $tenant, int $tokens, string $feature = 'reply', array $attributes = []): AIUsage
    {
        Cache::flush();

        $usage = (new AIUsage)->forceFill([
            'tenant_id' => $tenant->id,
            'feature' => $feature,
            'provider' => 'fake',
            'model' => 'fake',
            'status' => AIUsage::SUCCEEDED,
            'prompt_tokens' => $tokens,
            'completion_tokens' => 0,
            'total_tokens' => $tokens,
            'cost' => 0,
            ...$attributes,
        ]);
        $usage->save();

        return $usage;
    }
}
