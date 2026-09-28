<?php

namespace App\Domain\Lead\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Lead\Events\LeadAssigned;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns a lead to a team member. Assignable members are active members of the current
 * tenant whose roles can work leads (`leads.update`, or a grants-all role).
 */
class AssignLead
{
    public const WORK_PERMISSION = 'leads.update';

    public function __construct(
        private readonly TenantContext $context,
        private readonly RecordActivity $recordActivity,
    ) {}

    public function handle(Lead $lead, ?TenantUser $assignee, ?User $actor = null, bool $automatic = false): Lead
    {
        if ($assignee && ! $this->isAssignable($assignee)) {
            throw ValidationException::withMessages(['assigned_tenant_user_id' => 'This team member cannot be assigned leads.']);
        }

        if ($lead->assigned_tenant_user_id === $assignee?->id) {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $assignee, $actor, $automatic) {
            $previous = $lead->assignee?->loadMissing('user:id,name');

            $lead->forceFill(['assigned_tenant_user_id' => $assignee?->id])->save();
            $lead->setRelation('assignee', $assignee);

            $this->recordActivity->handle('assigned', lead: $lead, actor: $actor, metadata: [
                'from' => $previous ? ['id' => $previous->id, 'name' => $previous->user?->name] : null,
                'to' => $assignee ? ['id' => $assignee->id, 'name' => $assignee->loadMissing('user:id,name')->user?->name] : null,
                'automatic' => $automatic,
            ]);

            LeadAssigned::dispatch($lead, $assignee, $automatic);

            return $lead;
        });
    }

    /**
     * Assign to the assignable member with the fewest open leads (ties: longest-standing member).
     * Leaves the lead unassigned when nobody is assignable.
     */
    public function autoAssign(Lead $lead, ?User $actor = null): Lead
    {
        $assignee = $this->pickAutomatically();

        return $assignee ? $this->handle($lead, $assignee, $actor, automatic: true) : $lead;
    }

    public function pickAutomatically(): ?TenantUser
    {
        $members = $this->assignableMembers();

        if ($members->isEmpty()) {
            return null;
        }

        $load = Lead::query()->open()
            ->whereIn('assigned_tenant_user_id', $members->modelKeys())
            ->groupBy('assigned_tenant_user_id')
            ->selectRaw('assigned_tenant_user_id, count(*) as aggregate')
            ->pluck('aggregate', 'assigned_tenant_user_id');

        return $members->sortBy(fn (TenantUser $member) => [(int) ($load[$member->id] ?? 0), $member->id])->first();
    }

    /** @return Collection<int, TenantUser> */
    public function assignableMembers(): Collection
    {
        return $this->assignableQuery()->with('user:id,name,email')->orderBy('id')->get();
    }

    public function isAssignable(TenantUser $member): bool
    {
        return $this->assignableQuery()->whereKey($member->id)->exists();
    }

    private function assignableQuery(): Builder
    {
        return TenantUser::query()
            ->where('tenant_id', $this->context->tenant()->id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('user', fn (Builder $user) => $user->where('status', UserStatus::Active))
            ->whereHas('roles', fn (Builder $role) => $role->where(fn (Builder $grant) => $grant
                ->where('grants_all', true)
                ->orWhereHas('permissions', fn (Builder $permission) => $permission->where('key', self::WORK_PERMISSION))));
    }
}
