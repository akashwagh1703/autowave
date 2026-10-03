<?php

namespace App\Domain\Billing\Enums;

/**
 * Where a business stands, worked out from its subscription dates (Entitlements::state()).
 *
 * trial / active → due (grace, full access) → read_only → locked. `unlimited` never ends (internal business).
 */
enum SubscriptionState: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Due = 'due';
    case ReadOnly = 'read_only';
    case Locked = 'locked';
    case Unlimited = 'unlimited';

    public function hasFullAccess(): bool
    {
        return ! in_array($this, [self::ReadOnly, self::Locked], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => __('Free trial'),
            self::Active => __('Active'),
            self::Due => __('Payment due'),
            self::ReadOnly => __('Read-only'),
            self::Locked => __('Locked'),
            self::Unlimited => __('Active'),
        };
    }
}
