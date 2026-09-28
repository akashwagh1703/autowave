<?php

namespace App\Domain\Education\Events;

use App\Domain\Education\Models\FeeInstalment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `fee.due_soon`: an unpaid instalment falls due within the reminder window. */
class FeeDueSoon implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly FeeInstalment $instalment) {}
}
