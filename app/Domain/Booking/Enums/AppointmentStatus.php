<?php

namespace App\Domain\Booking\Enums;

/**
 * Appointment lifecycle: pending → confirmed → completed, with cancelled and no_show as the other
 * ends. Completed, cancelled and no_show are final.
 */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /** Statuses that occupy the resource's time (mirrors the appointments_no_overlap constraint). */
    public const BLOCKING = ['pending', 'confirmed', 'completed'];

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    /** Still upcoming or in progress: can be rescheduled, confirmed, completed or cancelled. */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Confirmed;
    }

    public function blocksTime(): bool
    {
        return in_array($this->value, self::BLOCKING, true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Completed, self::Cancelled, self::NoShow],
            self::Confirmed => [self::Completed, self::Cancelled, self::NoShow],
            default => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /** Whether the appointment must have started before moving to this status. */
    public function requiresStart(): bool
    {
        return $this === self::Completed || $this === self::NoShow;
    }
}
