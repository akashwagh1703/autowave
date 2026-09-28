<?php

namespace App\Domain\Lead\Events;

use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Models\TenantUser;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A lead was assigned (or unassigned when $assignee is null). */
class LeadAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly ?TenantUser $assignee,
        public readonly bool $automatic = false,
    ) {}
}
