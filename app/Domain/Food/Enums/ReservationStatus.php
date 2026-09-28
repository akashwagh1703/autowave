<?php

namespace App\Domain\Food\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Seated = 'seated';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /** Statuses that hold the table (reservations_no_overlap uses the same list). */
    public const HOLDING = ['pending', 'confirmed', 'seated'];

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Confirmed => __('Confirmed'),
            self::Seated => __('Seated'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
            self::NoShow => __('No-show'),
        };
    }

    public function holdsTable(): bool
    {
        return in_array($this->value, self::HOLDING, true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Seated, self::Cancelled, self::NoShow],
            self::Confirmed => [self::Seated, self::Cancelled, self::NoShow],
            self::Seated => [self::Completed],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
