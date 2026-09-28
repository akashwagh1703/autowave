<?php

namespace App\Http\Presenters;

use App\Domain\Activity\Models\Activity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\TenantUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Browser-safe shapes for CRM pages. Only fields listed here reach the frontend.
 * Timestamps are ISO-8601 UTC; the frontend formats them in the tenant timezone.
 */
final class CrmPresenter
{
    /** @return array<string, mixed> */
    public static function lead(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'interest' => $lead->interest,
            'estimated_value' => $lead->estimated_value,
            'next_followup_at' => $lead->next_followup_at?->toIso8601String(),
            'last_contacted_at' => $lead->last_contacted_at?->toIso8601String(),
            'converted_at' => $lead->converted_at?->toIso8601String(),
            'lost_at' => $lead->lost_at?->toIso8601String(),
            'lost_reason' => $lead->lost_reason,
            'created_at' => $lead->created_at?->toIso8601String(),
            'stage' => $lead->relationLoaded('stage') && $lead->stage ? self::stage($lead->stage) : null,
            'source' => $lead->relationLoaded('source') && $lead->source ? ['id' => $lead->source->id, 'name' => $lead->source->name] : null,
            'assignee' => $lead->relationLoaded('assignee') && $lead->assignee ? self::member($lead->assignee) : null,
            'customer' => $lead->relationLoaded('customer') && $lead->customer
                ? ['id' => $lead->customer->id, 'name' => $lead->customer->name, 'deleted' => $lead->customer->trashed()]
                : null,
            'lead_stage_id' => $lead->lead_stage_id,
            'lead_source_id' => $lead->lead_source_id,
            'assigned_tenant_user_id' => $lead->assigned_tenant_user_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'city' => $customer->city,
            'address' => $customer->address,
            'tags' => $customer->tags ?? [],
            'notes' => $customer->notes,
            'created_at' => $customer->created_at?->toIso8601String(),
            'leads_count' => $customer->leads_count ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public static function activity(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'type' => $activity->type,
            'body' => $activity->body,
            'metadata' => $activity->metadata ?? [],
            'occurred_at' => $activity->occurred_at?->toIso8601String(),
            'user' => $activity->user ? ['id' => $activity->user->id, 'name' => $activity->user->name] : null,
            'lead_id' => $activity->lead_id,
            'lead' => $activity->relationLoaded('lead') && $activity->lead
                ? ['id' => $activity->lead->id, 'name' => $activity->lead->name, 'deleted' => $activity->lead->trashed()]
                : null,
            'appointment_id' => $activity->appointment_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function stage(LeadStage $stage): array
    {
        return [
            'id' => $stage->id,
            'code' => $stage->code,
            'name' => $stage->name,
            'color' => $stage->color,
            'outcome' => $stage->outcome->value,
            'is_active' => $stage->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function source(LeadSource $source): array
    {
        return [
            'id' => $source->id,
            'code' => $source->code,
            'name' => $source->name,
            'is_active' => $source->is_active,
        ];
    }

    /** @return array{id: int, name: ?string} */
    public static function member(TenantUser $member): array
    {
        return ['id' => $member->id, 'name' => $member->user?->name];
    }

    /**
     * @param  callable(mixed): array<string, mixed>  $map
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|null>}
     */
    public static function paginated(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'data' => collect($paginator->items())->map($map)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
