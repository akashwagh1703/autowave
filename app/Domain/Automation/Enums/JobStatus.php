<?php

namespace App\Domain\Automation\Enums;

/**
 * One step execution: pending (waiting for run_at) → queued (on the queue) → running →
 * completed | failed | cancelled. Only pending or queued rows can be claimed by a worker.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public const CLAIMABLE = ['pending', 'queued'];
}
