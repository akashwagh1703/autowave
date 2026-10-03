<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Platform\Support\PlatformSettings;

/**
 * Super Admin → Settings → Billing (platform setting `billing`, defaults in config('billing.settings')):
 * enforcement, payment method switches, seller details, GST, UPI and bank details, the UPI QR image.
 * None of it is secret (payers see the payment details); gateway keys stay in .env.
 */
class BillingSettings
{
    public const KEY = 'billing';

    public function __construct(
        private readonly PlatformSettings $platform,
        private readonly PaymentGateways $gateways,
    ) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = $this->platform->get(self::KEY);

        return array_replace_recursive(config('billing.settings'), is_array($stored) ? $stored : []);
    }

    public function get(string $key): mixed
    {
        return data_get($this->all(), $key);
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): void
    {
        $this->platform->set(self::KEY, array_replace($this->all(), $values));
    }

    /** Whether ended plans make a business read-only and then locked. Off: states show, nobody is blocked. */
    public function enforced(): bool
    {
        return (bool) $this->get('enforce');
    }

    public function manualEnabled(): bool
    {
        return (bool) $this->get('manual_enabled');
    }

    /** Switched on and the gateway has its keys. */
    public function onlineEnabled(): bool
    {
        return (bool) $this->get('online_enabled') && $this->gateways->configured();
    }

    public function gstEnabled(): bool
    {
        return (bool) $this->get('gst.enabled') && filled($this->get('gst.gstin'));
    }

    /** @return array{disk: string, path: string, mime: string, updated_at: string, updated_by: ?string}|null */
    public function qr(): ?array
    {
        $qr = $this->get('qr');

        return is_array($qr) && isset($qr['disk'], $qr['path']) ? $qr : null;
    }

    /** What an owner sees to pay by hand; null when manual payment is off. */
    public function manualDetails(): ?array
    {
        if (! $this->manualEnabled()) {
            return null;
        }

        $bank = array_filter($this->get('bank') ?? []);

        return [
            'upi_id' => $this->get('upi.id'),
            'payee' => $this->get('upi.payee') ?: $this->get('seller.name'),
            'has_qr' => $this->qr() !== null,
            'bank' => $bank === [] ? null : $bank,
            'instructions' => $this->get('instructions'),
        ];
    }

    public static function paymentReference(int $tenantId): string
    {
        return 'AW-'.$tenantId;
    }

    /** A UPI payment link with the amount filled in (opens Google Pay, PhonePe, …), or null without a UPI ID. */
    public function upiLink(int $amountPaise, string $note): ?string
    {
        $id = $this->get('upi.id');

        if (! filled($id)) {
            return null;
        }

        return 'upi://pay?'.http_build_query([
            'pa' => $id,
            'pn' => $this->get('upi.payee') ?: $this->get('seller.name'),
            'am' => number_format($amountPaise / 100, 2, '.', ''),
            'cu' => config('billing.currency'),
            'tn' => mb_substr($note, 0, 50),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
