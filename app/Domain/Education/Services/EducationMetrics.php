<?php

namespace App\Domain\Education\Services;

use App\Domain\Education\Enums\DemoStatus;
use App\Domain\Education\Models\DemoClass;
use App\Domain\Education\Models\Enrolment;
use App\Domain\Education\Models\FeeInstalment;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;

/**
 * Live figures for the coaching dashboard widgets. Computed only with the education engine; each
 * figure needs the permission of the page it links to.
 */
class EducationMetrics
{
    public const ADMISSION_DAYS = 30;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $widgets
     * @return array<string, array{value: int|float|string, type: string, hint: string, href: ?string}>
     */
    public function for(User $user, array $widgets): array
    {
        if (! $this->context->hasEngine('education')) {
            return [];
        }

        $students = $user->can('students.view');
        $today = TenantTime::now()->toDateString();
        $todayStart = TenantTime::now()->startOfDay()->utc();
        $metrics = [];

        foreach ($widgets as $widget) {
            $metric = match (true) {
                $students && $widget === 'admissions' => [
                    'value' => Enrolment::query()->where('enrolled_on', '>=', TenantTime::now()->subDays(self::ADMISSION_DAYS - 1)->toDateString())->count(),
                    'type' => 'number',
                    'hint' => 'Admissions, last '.self::ADMISSION_DAYS.' days',
                    'href' => route('students.index', ['status' => 'all']),
                ],
                $students && $widget === 'students' => [
                    'value' => Enrolment::query()->active()->distinct()->count('customer_id'),
                    'type' => 'number',
                    'hint' => 'Active students',
                    'href' => route('students.index'),
                ],
                $widget === 'fees_due' && $user->can('fees.view') => [
                    'value' => number_format((float) FeeInstalment::query()->outstanding()->whereDate('due_on', '<', $today)
                        ->selectRaw('coalesce(sum(amount - amount_paid), 0) as due')->value('due'), 2, '.', ''),
                    'type' => 'currency',
                    'hint' => 'Overdue fees',
                    'href' => route('fees.index'),
                ],
                $students && $widget === 'demo_classes' => [
                    'value' => DemoClass::query()->where('status', DemoStatus::Scheduled)->where('scheduled_at', '>=', $todayStart)->count(),
                    'type' => 'number',
                    'hint' => 'Upcoming demo classes',
                    'href' => route('demos.index'),
                ],
                default => null,
            };

            if ($metric) {
                $metrics[$widget] = $metric;
            }
        }

        return $metrics;
    }
}
