<?php

namespace App\Domain\AI\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** AI cannot be used for this business right now (module off, switched off, not configured, over the cap). */
class AIUnavailable extends RuntimeException
{
    public const MODULE = 'module';

    public const DISABLED = 'disabled';

    public const NOT_CONFIGURED = 'not_configured';

    public const LIMIT = 'limit';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::describe($reason));
    }

    public static function describe(string $reason): string
    {
        return match ($reason) {
            self::MODULE => __('AI is not part of this business’s plan.'),
            self::DISABLED => __('AI is switched off in Settings → AI.'),
            self::NOT_CONFIGURED => __('AI is not set up on this server yet.'),
            self::LIMIT => __('This month’s AI allowance is used up. It resets on the 1st.'),
            default => __('AI is not available right now.'),
        };
    }

    /** Expected state, not an error: nothing to log. */
    public function report(): bool
    {
        return true;
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], 409)
            : back()->with('error', $this->getMessage());
    }
}
