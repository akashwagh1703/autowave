<?php

namespace App\Domain\Website\Support;

use App\Domain\Booking\Models\Appointment;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Support\Facades\URL;

/**
 * Signed public links so a customer can cancel or reschedule their online booking (AW-037).
 * Signature is relative (host differs from the app host that may generate the URL).
 */
class ManageBookingLink
{
    public static function url(Appointment $appointment, ?Tenant $tenant = null): ?string
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

        $expires = $appointment->starts_at->copy()->addDay();

        if ($expires->isPast()) {
            $expires = now()->addDay();
        }

        $path = URL::temporarySignedRoute(
            'tenant.booking.manage',
            $expires,
            ['appointment' => $appointment->id],
            absolute: false,
        );

        return $base.$path;
    }
}
