<?php

namespace App\Domain\AI\Data;

final class ChatResponse
{
    /**
     * @param  list<array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls = [],
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly ?float $cost = null,
        public readonly ?string $model = null,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function text(): string
    {
        return trim((string) $this->content);
    }

    /**
     * The content as a JSON object. Models sometimes wrap JSON in a code fence; that is tolerated.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $text = $this->text();

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $match)) {
            $text = $match[1];
        } elseif (($start = strpos($text, '{')) !== false && ($end = strrpos($text, '}')) !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
