<?php

namespace App\Domain\Billing\Gateways;

/**
 * An online payment provider (BILLING_GATEWAY). Online payments are one-off payments per period, applied like
 * manual ones, so a business can move between manual and online payment at any time.
 */
interface PaymentGateway
{
    public function name(): string;

    /** All keys are present in .env. */
    public function configured(): bool;
}
