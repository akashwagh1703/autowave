<?php

namespace App\Http\Controllers\App;

use App\Domain\Activity\Actions\LogActivity;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Lead\Actions\AssignLead;
use App\Domain\Lead\Actions\ChangeLeadStage;
use App\Domain\Lead\Actions\DeleteLead;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\LogActivityRequest;
use App\Models\User;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pipeline actions on existing leads: stage moves (convert / lost / reactivate), assignment,
 * activity logging and bulk actions.
 */
class LeadActionController extends Controller
{
    public const BULK_LIMIT = 100;

    public function __construct(private readonly TenantContext $context) {}

    public function stage(Request $request, Lead $lead, ChangeLeadStage $changeStage): RedirectResponse
    {
        $validated = $request->validate([
            'lead_stage_id' => ['required', 'integer'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $lead = $changeStage->handle($lead, $this->findStage($validated['lead_stage_id']), $request->user(), $validated['lost_reason'] ?? null);

        return back()->with('success', match ($lead->stage->outcome) {
            StageOutcome::Won => __('Lead converted. :name is now a customer.', ['name' => $lead->customer?->name ?? $lead->name]),
            StageOutcome::Lost => __('Lead marked as lost.'),
            StageOutcome::Open => __('Lead moved to :stage.', ['stage' => $lead->stage->name]),
        });
    }

    public function assign(Request $request, Lead $lead, AssignLead $assignLead): RedirectResponse
    {
        $validated = $request->validate(['assigned_tenant_user_id' => ['nullable', 'integer']]);

        $assignLead->handle($lead, $this->member($validated['assigned_tenant_user_id'] ?? null), $request->user());

        return back()->with('success', __('Assignment updated.'));
    }

    public function activity(LogActivityRequest $request, Lead $lead, LogActivity $logActivity): RedirectResponse
    {
        $logActivity->handle(
            $lead,
            $request->validated('type'),
            $request->validated('body'),
            $request->user(),
            TenantTime::parse($request->validated('occurred_at')),
            $request->boolean('update_followup'),
            TenantTime::parse($request->validated('next_followup_at')),
        );

        return back()->with('success', __('Activity logged.'));
    }

    public function bulk(Request $request, AssignLead $assignLead, ChangeLeadStage $changeStage, DeleteLead $deleteLead, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['assign', 'stage', 'delete'])],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'ids.*' => ['integer', 'distinct'],
            'assigned_tenant_user_id' => ['nullable', 'integer'],
            'lead_stage_id' => ['required_if:action,stage', 'nullable', 'integer'],
        ]);

        Gate::authorize(match ($validated['action']) {
            'assign' => 'leads.assign',
            'stage' => 'leads.update',
            'delete' => 'leads.delete',
        });

        // Tenant-scoped: ids from another tenant simply do not match.
        $leads = Lead::query()->whereKey($validated['ids'])->get();
        $user = $request->user();

        DB::transaction(function () use ($validated, $leads, $user, $assignLead, $changeStage, $deleteLead, $audit) {
            match ($validated['action']) {
                'assign' => $this->bulkAssign($leads, $this->member($validated['assigned_tenant_user_id'] ?? null), $user, $assignLead),
                'stage' => $this->bulkStage($leads, $this->findStage((int) $validated['lead_stage_id']), $user, $changeStage),
                'delete' => $leads->each(fn (Lead $lead) => $deleteLead->handle($lead)),
            };

            $audit->log("leads.bulk_{$validated['action']}", null, ['lead_ids' => $leads->modelKeys()]);
        });

        return back()->with('success', trans_choice('{1} :count lead updated.|[2,*] :count leads updated.', $leads->count(), ['count' => $leads->count()]));
    }

    /** @param  Collection<int, Lead>  $leads */
    private function bulkAssign(Collection $leads, ?TenantUser $member, User $user, AssignLead $assignLead): void
    {
        foreach ($leads as $lead) {
            $assignLead->handle($lead, $member, $user);
        }
    }

    /** @param  Collection<int, Lead>  $leads */
    private function bulkStage(Collection $leads, LeadStage $stage, User $user, ChangeLeadStage $changeStage): void
    {
        foreach ($leads as $lead) {
            $changeStage->handle($lead, $stage, $user);
        }
    }

    private function findStage(int $id): LeadStage
    {
        return LeadStage::query()->find($id)
            ?? throw ValidationException::withMessages(['lead_stage_id' => __('Choose a valid stage.')]);
    }

    private function member(?int $id): ?TenantUser
    {
        if ($id === null) {
            return null;
        }

        return TenantUser::query()->where('tenant_id', $this->context->tenant()->id)->find($id)
            ?? throw ValidationException::withMessages(['assigned_tenant_user_id' => __('This team member cannot be assigned leads.')]);
    }
}
