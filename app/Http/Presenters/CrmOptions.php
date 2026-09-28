<?php

namespace App\Http\Presenters;

use App\Domain\Lead\Actions\AssignLead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\TenantUser;

/**
 * Option lists for CRM forms and filters in the current tenant.
 */
class CrmOptions
{
    public function __construct(private readonly AssignLead $assignLead) {}

    /** @return list<array<string, mixed>> active stages, plus inactive ones that are still in use */
    public function stages(?int $includeId = null): array
    {
        return LeadStage::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($includeId, fn ($q) => $q->orWhere('id', $includeId)))
            ->ordered()
            ->get()
            ->map(fn (LeadStage $stage) => CrmPresenter::stage($stage))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function sources(?int $includeId = null): array
    {
        return LeadSource::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($includeId, fn ($q) => $q->orWhere('id', $includeId)))
            ->ordered()
            ->get()
            ->map(fn (LeadSource $source) => CrmPresenter::source($source))
            ->all();
    }

    /** @return list<array{id: int, name: ?string}> */
    public function members(): array
    {
        return $this->assignLead->assignableMembers()
            ->map(fn (TenantUser $member) => CrmPresenter::member($member))
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    public function activityTypes(): array
    {
        return collect(config('crm.loggable_activities'))
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }
}
