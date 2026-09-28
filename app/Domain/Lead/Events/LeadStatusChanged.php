<?php

namespace App\Domain\Lead\Events;

use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadStage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `lead.status_changed` (stage moved). */
class LeadStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly LeadStage $from,
        public readonly LeadStage $to,
    ) {}
}
