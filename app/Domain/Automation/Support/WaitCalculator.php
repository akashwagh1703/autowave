<?php

namespace App\Domain\Automation\Support;

use App\Domain\Automation\Models\AutomationJob;
use Carbon\CarbonImmutable;

/**
 * When the step after a wait is due. `delay` counts from now; `before_start` / `after_start` are
 * anchored to the appointment start so they can be re-timed when it is rescheduled.
 */
class WaitCalculator
{
    /**
     * @param  array{mode: string, amount: int, unit: string}  $config
     * @return array{run_at: CarbonImmutable, anchor: ?string, offset_minutes: ?int}
     */
    public function schedule(array $config, SubjectContext $context, CarbonImmutable $now): array
    {
        $minutes = self::minutes($config);
        $start = $context->appointment?->starts_at;

        return match ($config['mode']) {
            'before_start' => $start
                ? ['run_at' => CarbonImmutable::instance($start)->subMinutes($minutes), 'anchor' => AutomationJob::ANCHOR_APPOINTMENT_START, 'offset_minutes' => -$minutes]
                : ['run_at' => $now, 'anchor' => null, 'offset_minutes' => null],
            'after_start' => $start
                ? ['run_at' => CarbonImmutable::instance($start)->addMinutes($minutes), 'anchor' => AutomationJob::ANCHOR_APPOINTMENT_START, 'offset_minutes' => $minutes]
                : ['run_at' => $now, 'anchor' => null, 'offset_minutes' => null],
            default => ['run_at' => $now->addMinutes($minutes), 'anchor' => null, 'offset_minutes' => null],
        };
    }

    /** @param  array{amount: int, unit: string}  $config */
    public static function minutes(array $config): int
    {
        return (int) $config['amount'] * (int) config('automation.wait_units.'.$config['unit'], 1);
    }
}
