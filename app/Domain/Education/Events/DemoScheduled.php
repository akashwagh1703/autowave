<?php

namespace App\Domain\Education\Events;

use App\Domain\Education\Models\DemoClass;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `demo.scheduled`. */
class DemoScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly DemoClass $demo) {}
}
