<?php

namespace App\Domain\Automation\Enums;

/**
 * pending → running ⇄ waiting → completed, or skipped (a condition was not met), failed (a step
 * ran out of attempts) or cancelled (turned off, subject gone, or cancelled by a user).
 */
enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public const IN_PROGRESS = ['pending', 'running', 'waiting'];

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Waiting => 'Waiting',
            self::Completed => 'Completed',
            self::Skipped => 'Skipped',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isFinal(): bool
    {
        return ! in_array($this->value, self::IN_PROGRESS, true);
    }
}
