<?php

namespace App\Domain\Commerce\Enums;

/** Derived from the recorded payments: nothing, part or all of the order total. */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Partial => 'Partly paid',
            self::Paid => 'Paid',
        };
    }

    public static function for(string $paid, string $total): self
    {
        return match (true) {
            bccomp($paid, '0', 2) <= 0 => bccomp($total, '0', 2) <= 0 ? self::Paid : self::Unpaid,
            bccomp($paid, $total, 2) >= 0 => self::Paid,
            default => self::Partial,
        };
    }
}
