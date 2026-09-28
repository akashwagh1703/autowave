<?php

namespace App\Domain\Automation\Actions\Steps;

use App\Domain\Automation\Support\ActionContext;
use App\Domain\Automation\Support\ActionResult;
use App\Domain\Automation\Support\AutomationCatalog;
use App\Domain\Lead\Actions\AssignLead;
use App\Domain\Tenant\Models\TenantUser;
use Illuminate\Validation\Rule;

/** Assigns the lead automatically (fewest open leads) or to a chosen team member. */
class AssignLeadStep implements StepAction
{
    public function __construct(
        private readonly AssignLead $assignLead,
        private readonly AutomationCatalog $catalog,
    ) {}

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['auto', 'member'])],
            'tenant_user_id' => ['exclude_unless:mode,member', 'required', Rule::in($this->catalog->optionValues('members'))],
        ];
    }

    public function normalize(array $config): array
    {
        return $config['mode'] === 'member'
            ? ['mode' => 'member', 'tenant_user_id' => (int) $config['tenant_user_id']]
            : ['mode' => 'auto'];
    }

    public function handle(ActionContext $context, array $config): ActionResult
    {
        $lead = $context->subject->lead;

        if (! $lead) {
            return ActionResult::skipped('There is no lead to assign.');
        }

        if ($config['mode'] === 'auto') {
            if ($lead->assigned_tenant_user_id) {
                return ActionResult::skipped('The lead is already assigned to '.$lead->assignee?->user?->name.'.');
            }

            $lead = $this->assignLead->autoAssign($lead);

            return $lead->assigned_tenant_user_id
                ? ActionResult::completed('Assigned to '.$lead->assignee?->loadMissing('user')->user?->name.'.')
                : ActionResult::skipped('Nobody on the team can be assigned leads.');
        }

        $member = TenantUser::query()->with('user')->find($config['tenant_user_id']);

        if (! $member || ! $this->assignLead->isAssignable($member)) {
            return ActionResult::skipped('The chosen team member can no longer be assigned leads.');
        }

        if ($lead->assigned_tenant_user_id === $member->id) {
            return ActionResult::skipped("The lead is already assigned to {$member->user?->name}.");
        }

        $this->assignLead->handle($lead, $member);

        return ActionResult::completed("Assigned to {$member->user?->name}.");
    }
}
