<?php

namespace App\Domain\Education\Services;

use App\Domain\Education\Events\FeeDueSoon;
use App\Domain\Education\Events\FeeOverdue;
use App\Domain\Education\Models\FeeInstalment;
use App\Domain\Education\Support\EducationSettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\TenantTime;

/**
 * Fires the fee automation triggers for the current tenant (ADR-020). Each instalment of an active
 * enrolment triggers `fee.due_soon` once (reminded_at) when it falls due within the tenant's reminder
 * window, and `fee.overdue` once (overdue_notified_at) the day after its due date if still unpaid.
 * The timestamps are claimed with a conditional update, so overlapping runs never fire twice.
 */
class FeeReminders
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EducationSettings $settings,
    ) {}

    /** @return array{due_soon: int, overdue: int} */
    public function run(): array
    {
        if (! $this->context->hasEngine('education')) {
            return ['due_soon' => 0, 'overdue' => 0];
        }

        $today = TenantTime::now()->toDateString();
        $until = TenantTime::now()->addDays($this->settings->reminderDaysBefore())->toDateString();

        $dueSoon = $this->fire(
            FeeInstalment::query()->outstanding()->whereNull('reminded_at')->where('due_on', '>=', $today)->where('due_on', '<=', $until),
            'reminded_at',
            FeeDueSoon::class,
        );

        $overdue = $this->fire(
            FeeInstalment::query()->outstanding()->whereNull('overdue_notified_at')->where('due_on', '<', $today),
            'overdue_notified_at',
            FeeOverdue::class,
        );

        return ['due_soon' => $dueSoon, 'overdue' => $overdue];
    }

    /** @param  class-string  $event */
    private function fire($query, string $column, string $event): int
    {
        $count = 0;

        foreach ($query->orderBy('id')->limit(1000)->pluck('id') as $id) {
            $claimed = FeeInstalment::query()->whereKey($id)->whereNull($column)->update([$column => now()]);

            if ($claimed === 1) {
                $event::dispatch(FeeInstalment::query()->findOrFail($id));
                $count++;
            }
        }

        return $count;
    }
}
