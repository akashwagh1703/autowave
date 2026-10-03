<?php

namespace App\Domain\Billing\Gateways;

/** The gateway named by config('billing.gateway'); `none` means online payment is unavailable. */
class PaymentGateways
{
    public function current(): ?PaymentGateway
    {
        return match (config('billing.gateway')) {
            'razorpay' => new RazorpayGateway,
            default => null,
        };
    }

    public function configured(): bool
    {
        return (bool) $this->current()?->configured();
    }

    public function label(): string
    {
        $gateway = $this->current();

        return match (true) {
            $gateway === null => __('Not set up (BILLING_GATEWAY=none)'),
            ! $gateway->configured() => __(':name: keys missing in .env', ['name' => ucfirst($gateway->name())]),
            default => __(':name: ready', ['name' => ucfirst($gateway->name())]),
        };
    }
}
