<?php

namespace App\Domain\Lead\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Activity\Models\Activity;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Events\LeadConverted;
use App\Domain\Lead\Events\LeadStatusChanged;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Moves a lead to another stage. The target stage's outcome drives the transition:
 *
 * - won:  the lead is converted — linked to an existing or new customer (transactional).
 * - lost: lost_at and the optional reason are recorded.
 * - open from a closed stage: the lead is reactivated (closure fields cleared).
 *
 * Closing a lead clears its next follow-up.
 */
class ChangeLeadStage
{
    public function __construct(
        private readonly RecordActivity $recordActivity,
        private readonly ResolveLeadCustomer $resolveCustomer,
    ) {}

    /** Move to the tenant's first active stage with the given outcome (convert / mark lost / reactivate). */
    public function toOutcome(Lead $lead, StageOutcome $outcome, ?User $actor = null, ?string $reason = null): Lead
    {
        $stage = LeadStage::firstFor($outcome)
            ?? throw new LogicException("The tenant has no active [{$outcome->value}] lead stage.");

        return $this->handle($lead, $stage, $actor, $reason);
    }

    public function handle(Lead $lead, LeadStage $to, ?User $actor = null, ?string $reason = null): Lead
    {
        if (! $to->is_active) {
            throw ValidationException::withMessages(['lead_stage_id' => 'Choose an active stage.']);
        }

        return DB::transaction(function () use ($lead, $to, $actor, $reason) {
            $lead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $from = $lead->stage;

            if ($from->id === $to->id) {
                return $lead;
            }

            $reopening = $from->outcome->isClosed() && $to->outcome === StageOutcome::Open;

            if ($reopening && CreateLead::openDuplicateOf($lead->phone_normalized, $lead->id)) {
                throw ValidationException::withMessages(['lead_stage_id' => 'Another open lead already has this phone number; it cannot be reactivated.']);
            }

            $metadata = [
                'from' => ['id' => $from->id, 'name' => $from->name],
                'to' => ['id' => $to->id, 'name' => $to->name],
            ];
            $customer = null;
            $customerCreated = false;

            $lead->lead_stage_id = $to->id;

            if ($to->outcome === StageOutcome::Won) {
                [$customer, $customerCreated] = $this->resolveCustomer->handle($lead, $actor);
                $lead->fill(['customer_id' => $customer->id, 'converted_at' => now(), 'lost_at' => null, 'lost_reason' => null, 'next_followup_at' => null]);
                $metadata['customer'] = ['id' => $customer->id, 'name' => $customer->name, 'created' => $customerCreated];
                $type = 'converted';
            } elseif ($to->outcome === StageOutcome::Lost) {
                $lead->fill(['lost_at' => now(), 'lost_reason' => $reason, 'converted_at' => null, 'next_followup_at' => null]);
                $type = 'lost';
            } elseif ($reopening) {
                $lead->fill(['lost_at' => null, 'lost_reason' => null, 'converted_at' => null]);
                $type = 'reactivated';
            } else {
                $type = 'stage_changed';
            }

            $lead->save();
            $lead->setRelation('stage', $to);

            if ($customer) {
                Activity::query()->where('lead_id', $lead->id)->whereNull('customer_id')->update(['customer_id' => $customer->id]);
            }

            $this->recordActivity->handle($type, lead: $lead, actor: $actor, body: $to->outcome === StageOutcome::Lost ? $reason : null, metadata: $metadata);

            LeadStatusChanged::dispatch($lead, $from, $to);

            if ($customer) {
                LeadConverted::dispatch($lead, $customer, $customerCreated);
            }

            return $lead;
        });
    }
}
