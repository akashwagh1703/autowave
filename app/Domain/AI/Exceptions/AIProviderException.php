<?php

namespace App\Domain\AI\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The provider failed. `temporary` errors (timeouts, rate limits, 5xx) are worth retrying; the others
 * (bad request, bad key) are not. The message may contain provider details and is logged, never shown.
 */
class AIProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $temporary = true, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public function report(): bool
    {
        Log::warning('ai.provider_failed', ['error' => $this->getMessage(), 'status' => $this->status, 'temporary' => $this->temporary]);

        return true;
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = __('The AI service did not respond. Please try again in a moment.');

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'reason' => 'provider'], 503)
            : back()->with('error', $message);
    }
}
