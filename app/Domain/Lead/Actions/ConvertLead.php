<?php

namespace App\Domain\Lead\Actions;

use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Models\User;

/**
 * Converts a lead into a customer (master prompt §59): moves it to the first won stage, which
 * links or creates the customer, backfills the timeline and records the conversion in one
 * transaction.
 */
class ConvertLead
{
    public function __construct(private readonly ChangeLeadStage $changeStage) {}

    public function handle(Lead $lead, ?User $actor = null): Lead
    {
        return $this->changeStage->toOutcome($lead, StageOutcome::Won, $actor);
    }
}
