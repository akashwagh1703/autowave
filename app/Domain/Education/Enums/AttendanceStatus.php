<?php

namespace App\Domain\Education\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case Excused = 'excused';

    public function label(): string
    {
        return match ($this) {
            self::Present => __('Present'),
            self::Absent => __('Absent'),
            self::Late => __('Late'),
            self::Excused => __('Excused'),
        };
    }

    /** Counts towards the attendance rate. */
    public function attended(): bool
    {
        return $this === self::Present || $this === self::Late;
    }
}
