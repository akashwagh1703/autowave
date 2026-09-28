<?php

namespace App\Domain\Commerce\Events;

use App\Domain\Commerce\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `order.paid`. */
class OrderPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
