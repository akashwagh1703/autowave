<?php

namespace App\Domain\Lead\Events;

use App\Domain\Lead\Models\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `lead.updated` (details edited). */
class LeadUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $changed  attribute names that changed
     */
    public function __construct(
        public readonly Lead $lead,
        public readonly array $changed,
    ) {}
}
