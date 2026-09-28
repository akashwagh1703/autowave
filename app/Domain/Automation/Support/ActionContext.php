<?php

namespace App\Domain\Automation\Support;

use App\Domain\Automation\Models\AutomationRun;

final class ActionContext
{
    public function __construct(
        public readonly AutomationRun $run,
        public readonly int $step,
        public readonly SubjectContext $subject,
        public readonly string $idempotencyKey,
        public readonly ?string $automationName = null,
    ) {}

    public static function keyFor(AutomationRun $run, int $step): string
    {
        return "automation:{$run->id}:step:{$step}";
    }
}
