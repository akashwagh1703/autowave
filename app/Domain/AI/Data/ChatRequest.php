<?php

namespace App\Domain\AI\Data;

/**
 * One call to a chat model. Messages use the OpenAI/OpenRouter shape:
 * {role: system|user|assistant|tool, content, tool_calls?, tool_call_id?}.
 */
final class ChatRequest
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     */
    public function __construct(
        public readonly string $feature,
        public readonly array $messages,
        public readonly int $maxTokens,
        public readonly float $temperature,
        public readonly bool $json = false,
        public readonly array $tools = [],
        public readonly ?string $model = null,
    ) {}

    /**
     * A request with the feature's configured limits (config/ai.php `features`).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     */
    public static function for(string $feature, array $messages, bool $json = false, array $tools = []): self
    {
        $config = config("ai.features.{$feature}", []);

        return new self(
            feature: $feature,
            messages: $messages,
            maxTokens: (int) ($config['max_tokens'] ?? 500),
            temperature: (float) ($config['temperature'] ?? 0.3),
            json: $json,
            tools: $tools,
            model: $config['model'] ?? null,
        );
    }

    /** @param  list<array<string, mixed>>  $messages */
    public function withMessages(array $messages): self
    {
        return new self($this->feature, $messages, $this->maxTokens, $this->temperature, $this->json, $this->tools, $this->model);
    }

    public function withoutTools(): self
    {
        return new self($this->feature, $this->messages, $this->maxTokens, $this->temperature, $this->json, [], $this->model);
    }
}
