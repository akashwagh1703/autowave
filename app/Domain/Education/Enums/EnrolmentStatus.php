<?php

namespace App\Domain\Education\Enums;

enum EnrolmentStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Dropped = 'dropped';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Completed => __('Completed'),
            self::Dropped => __('Dropped'),
        };
    }
}
