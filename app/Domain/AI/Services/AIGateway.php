<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Contracts\AIProvider;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\ChatResponse;
use App\Domain\AI\Exceptions\AIProviderException;
use App\Domain\AI\Exceptions\AIUnavailable;
use App\Domain\AI\Models\AIUsage;
use App\Domain\AI\Support\AISettings;
use App\Domain\AI\Support\AIUsageMeter;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The single door to the AI provider: checks that AI is available for the current business, calls the
 * provider and meters every call (ADR-019). Always runs inside a tenant context.
 */
class AIGateway
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AISettings $settings,
        private readonly AIUsageMeter $meter,
    ) {}

    /** @return array{available: bool, reason: ?string, message: ?string} */
    public function status(): array
    {
        $reason = $this->unavailableReason();

        return ['available' => $reason === null, 'reason' => $reason, 'message' => $reason ? AIUnavailable::describe($reason) : null];
    }

    public function available(): bool
    {
        return $this->unavailableReason() === null;
    }

    /** @throws AIUnavailable */
    public function ensureAvailable(): void
    {
        if ($reason = $this->unavailableReason()) {
            throw new AIUnavailable($reason);
        }
    }

    /**
     * @throws AIUnavailable
     * @throws AIProviderException
     */
    public function chat(ChatRequest $request, ?User $user = null): ChatResponse
    {
        $this->ensureAvailable();

        $provider = $this->provider();
        $tenant = $this->context->tenant();
        $started = hrtime(true);

        try {
            $response = $provider->chat($request);
        } catch (Throwable $exception) {
            $error = $exception instanceof AIProviderException ? $exception : new AIProviderException($exception->getMessage());

            $this->meter->record($tenant, [
                'user_id' => $user?->id,
                'feature' => $request->feature,
                'provider' => $this->providerName(),
                'model' => $request->model ?? $provider->defaultModel(),
                'status' => AIUsage::FAILED,
                'duration_ms' => $this->elapsed($started),
                'error' => Str::limit($error->getMessage(), 500, ''),
            ]);

            throw $error;
        }

        $this->meter->record($tenant, [
            'user_id' => $user?->id,
            'feature' => $request->feature,
            'provider' => $this->providerName(),
            'model' => Str::limit((string) ($response->model ?? $request->model ?? $provider->defaultModel()), 100, ''),
            'status' => AIUsage::SUCCEEDED,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'total_tokens' => $response->totalTokens(),
            'cost' => $response->cost,
            'duration_ms' => $this->elapsed($started),
        ]);

        return $response;
    }

    public function providerName(): string
    {
        return (string) config('ai.provider');
    }

    private function unavailableReason(): ?string
    {
        return match (true) {
            ! $this->context->hasModule('ai') => AIUnavailable::MODULE,
            ! $this->settings->enabled() => AIUnavailable::DISABLED,
            ! $this->provider()->configured() => AIUnavailable::NOT_CONFIGURED,
            $this->meter->exceeded($this->context->tenant()) => AIUnavailable::LIMIT,
            default => null,
        };
    }

    private function provider(): AIProvider
    {
        $class = config('ai.providers.'.$this->providerName().'.class') ?? throw new InvalidArgumentException('Unknown AI provider ['.$this->providerName().'].');

        return app($class);
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
