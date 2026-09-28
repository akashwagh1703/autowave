<?php

namespace App\Domain\Website\Events;

use App\Domain\Lead\Models\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A visitor sent the website enquiry form. `newLead` is false when it was added to an open lead. */
class WebsiteEnquiryReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly bool $newLead,
    ) {}
}
