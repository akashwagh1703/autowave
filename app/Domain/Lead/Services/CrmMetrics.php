<?php

namespace App\Domain\Lead\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;

/**
 * Live figures for the CRM dashboard widgets. A widget is only computed when its module is
 * enabled and the user may see the underlying records; otherwise it is left out.
 */
class CrmMetrics
{
    public const RECENT_DAYS = 7;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $widgets
     * @return array<string, array{value: int|float|string, type: string, hint: string, href: ?string}>
     */
    public function for(User $user, array $widgets): array
    {
        $since = TenantTime::now()->subDays(self::RECENT_DAYS - 1)->startOfDay()->utc();
        $leads = $this->context->hasModule('leads') && $user->can('leads.view');
        $customers = $this->context->hasModule('customers') && $user->can('customers.view');

        $metrics = [];

        foreach ($widgets as $widget) {
            $metric = match (true) {
                $leads && in_array($widget, ['new_leads', 'new_enquiries'], true) => [
                    'value' => Lead::query()->where('created_at', '>=', $since)->count(),
                    'type' => 'number',
                    'hint' => 'Last '.self::RECENT_DAYS.' days',
                    'href' => route('leads.index', ['view' => 'all']),
                ],
                $leads && $widget === 'pending_followups' => [
                    'value' => Lead::query()->followUpDue()->count(),
                    'type' => 'number',
                    'hint' => 'Due today or overdue',
                    'href' => route('leads.index', ['view' => 'followup']),
                ],
                $leads && $widget === 'potential_revenue' => [
                    'value' => (string) (Lead::query()->open()->sum('estimated_value') ?? 0),
                    'type' => 'currency',
                    'hint' => 'Estimated value of open leads',
                    'href' => route('leads.index', ['sort' => 'value']),
                ],
                $customers && $widget === 'new_customers' => [
                    'value' => Customer::query()->where('created_at', '>=', $since)->count(),
                    'type' => 'number',
                    'hint' => 'Last '.self::RECENT_DAYS.' days',
                    'href' => route('customers.index'),
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
