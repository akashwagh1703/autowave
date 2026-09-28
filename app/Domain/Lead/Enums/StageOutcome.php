<?php

namespace App\Domain\Lead\Enums;

/**
 * What reaching a stage means, whatever the tenant calls it.
 */
enum StageOutcome: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'In progress',
            self::Won => 'Converted',
            self::Lost => 'Lost',
        };
    }
}
