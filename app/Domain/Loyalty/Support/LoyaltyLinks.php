<?php

namespace App\Domain\Loyalty\Support;

use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;

/** Public loyalty URLs on the business website (join page and customer card). */
class LoyaltyLinks
{
    public static function joinUrl(?Tenant $tenant = null): ?string
    {
        return self::siteUrl('/loyalty/join', $tenant);
    }

    public static function cardUrl(LoyaltyAccount $account, ?Tenant $tenant = null): ?string
    {
        return self::siteUrl('/loyalty/card/'.$account->public_token, $tenant);
    }

    private static function siteUrl(string $path, ?Tenant $tenant = null): ?string
    {
        $tenant ??= app(TenantContext::class)->tenant();
        $tenant->loadMissing('primaryDomain');
        $domain = $tenant->primaryDomain?->domain;

        if ($domain === null) {
            return null;
        }

        $app = parse_url((string) config('app.url'));
        $port = isset($app['port']) ? ':'.$app['port'] : '';

        return ($app['scheme'] ?? 'https').'://'.$domain.$port.$path;
    }
}
