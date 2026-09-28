<?php

namespace App\Support;

use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Wall-clock times entered in the business app are in the tenant's timezone; the database
 * stores UTC.
 */
final class TenantTime
{
    public static function timezone(): string
    {
        return app(TenantContext::class)->get()?->timezone ?? config('app.timezone');
    }

    /** Parse a local date-time string (e.g. an HTML datetime-local value) into UTC. */
    public static function parse(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value, self::timezone())->utc() : null;
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }
}
