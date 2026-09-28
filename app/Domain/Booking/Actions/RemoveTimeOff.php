<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Booking\Models\TimeOff;

class RemoveTimeOff
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(TimeOff $timeOff): void
    {
        $timeOff->delete();

        $this->audit->log('booking_resource.time_off_removed', $timeOff->resource()->withTrashed()->first(), [
            'starts_at' => $timeOff->starts_at->toIso8601String(),
            'ends_at' => $timeOff->ends_at->toIso8601String(),
        ]);
    }
}
