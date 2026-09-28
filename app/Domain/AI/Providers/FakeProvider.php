<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AIProvider;
use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\ChatResponse;
use Illuminate\Support\Str;

/**
 * Deterministic answers without a network call, for tests and local development without an API key.
 * Output is built from the request so the screens show something plausible, and is labelled as a sample.
 */
class FakeProvider implements AIProvider
{
    public const NOTE = '(Sample text — no AI provider is configured.)';

    public function configured(): bool
    {
        return true;
    }

    public function defaultModel(): ?string
    {
        return 'fake';
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $input = $this->lastUserText($request->messages);

        $content = match ($request->feature) {
            'extraction' => json_encode($this->extract($input)),
            'summary' => 'Summary: '.Str::limit(preg_replace('/\s+/', ' ', $this->transcript($input)) ?? '', 180).' '.self::NOTE,
            'reply' => 'Thank you for your message! We will get back to you with the details shortly. '.self::NOTE,
            'copy' => 'Here is a suggestion for you: '.Str::limit(trim(Str::after($input, 'Instructions:')), 120).' '.self::NOTE,
            'assistant' => null,
            default => self::NOTE,
        };

        if ($request->feature === 'assistant') {
            return $this->assistant($request);
        }

        return new ChatResponse($content, promptTokens: $this->tokens($request->messages), completionTokens: $this->tokens([['content' => (string) $content]]), cost: 0.0, model: 'fake');
    }

    private function assistant(ChatRequest $request): ChatResponse
    {
        $toolResults = array_values(array_filter($request->messages, fn (array $message) => ($message['role'] ?? null) === 'tool'));
        $tools = array_column($request->tools, 'name');

        if ($toolResults === [] && $tools !== []) {
            $name = in_array('business_overview', $tools, true) ? 'business_overview' : $tools[0];

            return new ChatResponse(null, [['id' => 'call_fake_1', 'name' => $name, 'arguments' => []]], $this->tokens($request->messages), 5, 0.0, 'fake');
        }

        $facts = $toolResults !== [] ? Str::limit((string) end($toolResults)['content'], 400) : 'I could not look anything up.';
        $content = "Here is what I found: {$facts} ".self::NOTE;

        return new ChatResponse($content, [], $this->tokens($request->messages), $this->tokens([['content' => $content]]), 0.0, 'fake');
    }

    /** @return array<string, mixed> */
    private function extract(string $text): array
    {
        preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $email);
        preg_match('/\b(?:my name is|i am|this is)\s+([A-Z][a-z]+(?:\s[A-Z][a-z]+)?)/', $text, $name);
        preg_match('/(?:₹|rs\.?|inr)\s?([\d,]+)/i', $text, $budget);

        return [
            'name' => $name[1] ?? null,
            'email' => $email[0] ?? null,
            'interest' => null,
            'budget' => isset($budget[1]) ? (float) str_replace(',', '', $budget[1]) : null,
            'preferred_time' => null,
            'summary' => Str::limit(preg_replace('/\s+/', ' ', $this->transcript($text)) ?? '', 120),
        ];
    }

    /** The part of a prompt after "Conversation:" / "Messages:", or the whole text. */
    private function transcript(string $input): string
    {
        foreach (['Conversation:', 'Messages:', 'Timeline:'] as $marker) {
            if (str_contains($input, $marker)) {
                return trim(Str::after($input, $marker));
            }
        }

        return trim($input);
    }

    /** @param  list<array<string, mixed>>  $messages */
    private function lastUserText(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) === 'user') {
                return (string) ($message['content'] ?? '');
            }
        }

        return '';
    }

    /** Roughly four characters per token. */
    private function tokens(array $messages): int
    {
        return (int) ceil(array_sum(array_map(fn (array $message) => mb_strlen((string) ($message['content'] ?? '')), $messages)) / 4);
    }
}
