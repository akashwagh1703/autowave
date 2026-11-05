<?php

namespace App\Domain\Customer\Events;

use App\Domain\Customer\Models\Customer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `customer.birthday`: once per customer per year. */
class CustomerBirthday implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Customer $customer,
        public readonly string $mode,
    ) {}
}
