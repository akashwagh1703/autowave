<?php

namespace Tests\Fixtures;

use App\Domain\Automation\Actions\Steps\StepAction;
use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;
use RuntimeException;

/** A test action that fails the first `$failures` times it runs. */
class FlakyStep implements StepAction
{
    public static int $failures = 0;

    public static int $handled = 0;

    public function rules(): array
    {
        return [];
    }

    public function normalize(array $config): array
    {
        return [];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        if (self::$failures > 0) {
            self::$failures--;

            throw new RuntimeException('Provider unavailable');
        }

        self::$handled++;

        return ActionResult::completed('Done.');
    }
}
