<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\ChatRequest;
use App\Domain\AI\Data\ChatResponse;
use App\Domain\AI\Exceptions\AIProviderException;

/**
 * A chat model provider (config/ai.php `providers`). Only AIGateway calls it.
 */
interface AIProvider
{
    /** Whether the provider is configured (for example, has an API key). */
    public function configured(): bool;

    /** @throws AIProviderException */
    public function chat(ChatRequest $request): ChatResponse;

    /** The model used when the request does not name one. */
    public function defaultModel(): ?string;
}
