<?php

namespace App\Domain\Education\Events;

use App\Domain\Education\Models\FeeInstalment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `fee.overdue`: an instalment is past its due date and still unpaid. */
class FeeOverdue implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly FeeInstalment $instalment) {}
}
