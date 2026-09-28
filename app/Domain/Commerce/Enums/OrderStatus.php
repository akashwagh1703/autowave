<?php

namespace App\Domain\Commerce\Enums;

/**
 * Order lifecycle: pending → confirmed → ready → completed, with cancelled as the other end.
 * Steps can be skipped (a counter sale goes straight to completed). Completed and cancelled are
 * final.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Statuses still being worked on. */
    public const OPEN = ['pending', 'confirmed', 'ready'];

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Ready => 'Ready',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** "Ready" means ready for pickup or out for delivery, depending on the fulfilment. */
    public function labelFor(string $fulfilment): string
    {
        return $this === self::Ready
            ? match ($fulfilment) {
                'pickup' => 'Ready for pickup',
                'delivery' => 'Out for delivery',
                'dine_in' => 'Ready to serve',
                default => 'Ready',
            }
        : $this->label();
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::OPEN, true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Ready, self::Completed, self::Cancelled],
            self::Confirmed => [self::Ready, self::Completed, self::Cancelled],
            self::Ready => [self::Completed, self::Cancelled],
            default => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
