<?php

namespace App\Domain\Loyalty\Support;

use App\Domain\Customer\Models\Customer;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Support\Facades\URL;

/** Signed public link so a customer can save their birthday after an order. */
class CustomerBirthdayLink
{
    public static function url(Customer $customer, ?Tenant $tenant = null): ?string
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

        $path = URL::temporarySignedRoute(
            'tenant.customers.birthday',
            now()->addDays(30),
            ['customer' => $customer->id],
            absolute: false,
        );

        return $base.$path;
    }
}
