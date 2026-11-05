<?php

namespace App\Domain\Commerce\Support;

use App\Domain\Billing\Support\QrCode;
use App\Domain\Commerce\Models\Order;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the QR payload for an order confirmation and stores a PNG on the private disk
 * for WhatsApp (Meta uploads JPEG/PNG from storage).
 */
class OrderQr
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CommerceSettings $settings,
    ) {}

    /**
     * Text encoded in the QR: signed status URL, order reference, or UPI pay link.
     */
    public function payload(Order $order): string
    {
        $confirmation = $this->settings->confirmation();

        return match ($confirmation['qr_payload']) {
            'upi' => $this->upiPayload($order, $confirmation) ?? $this->referencePayload($order),
            'reference' => $this->referencePayload($order),
            default => OrderStatusLink::url($order) ?? $this->referencePayload($order),
        };
    }

    /**
     * Writes (or overwrites) the PNG and returns the interactive image shape Meta expects.
     *
     * @return array{disk: string, path: string, mime: string, url: string}
     */
    public function store(Order $order): array
    {
        $tenant = $this->context->tenant();
        $disk = (string) config('files.disks.private');
        $path = "tenant/{$tenant->id}/orders/qr/{$order->id}.png";
        $png = QrCode::png($this->payload($order));

        Storage::disk($disk)->put($path, $png);

        return [
            'disk' => $disk,
            'path' => $path,
            'mime' => 'image/png',
            'url' => '',
        ];
    }

    private function referencePayload(Order $order): string
    {
        $tenant = $this->context->tenant();

        return 'AW-'.$tenant->id.'-'.$order->reference();
    }

    /** @param  array{upi_id: ?string, upi_payee: ?string}  $confirmation */
    private function upiPayload(Order $order, array $confirmation): ?string
    {
        if (! filled($confirmation['upi_id'] ?? null)) {
            return null;
        }

        $balance = (float) $order->balance();
        $amount = $balance > 0 ? $balance : (float) $order->total;
        $payee = $confirmation['upi_payee'] ?: $this->context->tenant()->name;

        return 'upi://pay?'.http_build_query([
            'pa' => $confirmation['upi_id'],
            'pn' => $payee,
            'am' => number_format(max(0, $amount), 2, '.', ''),
            'cu' => 'INR',
            'tn' => mb_substr($this->context->tenant()->name.' '.$order->reference(), 0, 50),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
