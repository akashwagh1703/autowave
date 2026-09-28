<?php

namespace App\Domain\Education\Enums;

enum DemoStatus: string
{
    case Scheduled = 'scheduled';
    case Attended = 'attended';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::Attended => __('Attended'),
            self::NoShow => __('No-show'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
