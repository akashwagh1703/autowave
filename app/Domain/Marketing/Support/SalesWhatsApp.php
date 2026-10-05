<?php

namespace App\Domain\Marketing\Support;

use App\Domain\Billing\Support\BillingSettings;
use App\Support\Phone;

/** The "Chat on WhatsApp" link for AutoWave sales: AUTOWAVE_SALES_WHATSAPP, else the seller phone. */
class SalesWhatsApp
{
    public function __construct(private readonly BillingSettings $billing) {}

    public function url(): ?string
    {
        $number = Phone::normalize(config('marketing.whatsapp') ?: ($this->billing->get('seller')['phone'] ?? null));

        return $number ? 'https://wa.me/'.ltrim($number, '+').'?text='.rawurlencode((string) config('marketing.whatsapp_message')) : null;
    }
}
