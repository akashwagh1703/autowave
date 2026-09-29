<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AIProvider;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\ChatResponse;
use App\Domain\AI\Exceptions\AIProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenRouter's OpenAI-compatible chat completions API. The key goes in the Authorization header, never
 * in URLs or logs.
 */
class OpenRouterProvider implements AIProvider
{
    public function configured(): bool
    {
        return filled(config('ai.openrouter.api_key'));
    }

    public function defaultModel(): ?string
    {
        return config('ai.openrouter.model');
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        if (! $this->configured()) {
            throw new AIProviderException('OpenRouter API key is not configured.', temporary: false);
        }

        $payload = array_filter([
            'model' => $request->model ?? $this->defaultModel(),
            'messages' => $request->messages,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'response_format' => $request->json ? ['type' => 'json_object'] : null,
            'tools' => $request->tools !== [] ? array_map(fn (array $tool) => ['type' => 'function', 'function' => $tool], $request->tools) : null,
            'reasoning' => $this->reasoning(),
            'usage' => ['include' => true],
        ], fn ($value) => $value !== null);

        try {
            $response = Http::withToken((string) config('ai.openrouter.api_key'))
                ->withHeaders(array_filter([
                    'HTTP-Referer' => config('ai.openrouter.referer'),
                    'X-Title' => config('ai.openrouter.title'),
                ]))
                ->acceptJson()
                ->timeout((int) config('ai.openrouter.timeout'))
                ->post(rtrim((string) config('ai.openrouter.base_url'), '/').'/chat/completions', $payload);
        } catch (ConnectionException $exception) {
            throw new AIProviderException('OpenRouter could not be reached: '.$exception->getMessage());
        }

        if ($response->failed()) {
            $status = $response->status();
            $error = Str::limit((string) ($response->json('error.message') ?? $response->body()), 300, '');

            throw new AIProviderException("OpenRouter returned {$status}: {$error}", temporary: $status === 408 || $status === 429 || $status >= 500, status: $status);
        }

        // OpenRouter can answer 200 with an error object (for example, the upstream model failed).
        if ($response->json('error')) {
            throw new AIProviderException('OpenRouter error: '.Str::limit((string) $response->json('error.message'), 300, ''));
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw new AIProviderException('OpenRouter returned no message.');
        }

        return new ChatResponse(
            content: isset($message['content']) && is_string($message['content']) ? $message['content'] : null,
            toolCalls: $this->toolCalls($message['tool_calls'] ?? []),
            promptTokens: (int) $response->json('usage.prompt_tokens', 0),
            completionTokens: (int) $response->json('usage.completion_tokens', 0),
            cost: is_numeric($response->json('usage.cost')) ? (float) $response->json('usage.cost') : null,
            model: $response->json('model'),
        );
    }

    /**
     * @return array<string, bool|string>|null
     */
    private function reasoning(): ?array
    {
        $setting = strtolower(trim((string) config('ai.openrouter.reasoning')));

        return match ($setting) {
            'off' => ['enabled' => false],
            'low', 'medium', 'high' => ['effort' => $setting],
            default => null,
        };
    }

    /**
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function toolCalls(mixed $calls): array
    {
        if (! is_array($calls)) {
            return [];
        }

        $result = [];

        foreach ($calls as $call) {
            $name = $call['function']['name'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $result[] = [
                'id' => (string) ($call['id'] ?? Str::random(12)),
                'name' => $name,
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        return $result;
    }
}
