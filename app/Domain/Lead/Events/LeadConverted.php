<?php

namespace App\Domain\Lead\Events;

use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class LeadConverted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly Customer $customer,
        public readonly bool $customerCreated,
    ) {}
}
