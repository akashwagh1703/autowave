<?php

namespace App\Domain\Commerce\Support;

use App\Domain\Commerce\Models\Order;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Support\Facades\URL;

/**
 * Signed public link so a customer can open their order status (and show the QR at pickup).
 * Signature is relative (host differs from the app host that may generate the URL).
 */
class OrderStatusLink
{
    public static function url(Order $order, ?Tenant $tenant = null): ?string
    {
        $tenant ??= app(TenantContext::class)->tenant();
        $tenant->loadMissing('primaryDomain');
        $domain = $tenant->primaryDomain?->domain;

        if ($domain === null) {
            return null;
        }

        $app = parse_url((string) config('app.url'));
        $port = isset($app['port']) ? ':'.$app['port'] : '';
        $base = ($app['scheme'] ?? 'https').'://'.$domain.$port;

        $expires = ($order->confirmed_at ?? now())->copy()->addDays(14);

        if ($expires->isPast()) {
            $expires = now()->addDay();
        }

        $path = URL::temporarySignedRoute(
            'tenant.orders.status',
            $expires,
            ['order' => $order->id],
            absolute: false,
        );

        return $base.$path;
    }
}
