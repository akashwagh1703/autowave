<?php

namespace App\Domain\Education\Events;

use App\Domain\Education\Models\Enrolment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Automation trigger `enrolment.created` (a student is admitted). */
class EnrolmentCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Enrolment $enrolment) {}
}
