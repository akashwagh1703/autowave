<?php

namespace App\Domain\Billing\Gateways;

/** Razorpay Checkout (one-off payments). Checkout and webhooks arrive in the online payments phase. */
class RazorpayGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'razorpay';
    }

    public function configured(): bool
    {
        $keys = config('billing.gateways.razorpay');

        return filled($keys['key_id'] ?? null) && filled($keys['key_secret'] ?? null) && filled($keys['webhook_secret'] ?? null);
    }
}
