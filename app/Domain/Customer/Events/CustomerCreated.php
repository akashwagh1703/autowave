<?php

namespace App\Domain\Customer\Events;

use App\Domain\Customer\Models\Customer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `customer.created`. */
class CustomerCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Customer $customer) {}
}
