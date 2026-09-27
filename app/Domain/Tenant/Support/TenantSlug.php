<?php

namespace App\Domain\Tenant\Support;

use App\Domain\Domain\Models\Domain;
use App\Domain\Domain\Support\Hostname;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tenant slugs double as the platform subdomain, so they must be valid DNS labels,
 * not reserved, and unique across tenants and domains.
 */
class TenantSlug
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])?$/';

    public static function isValid(string $slug): bool
    {
        return (bool) preg_match(self::PATTERN, $slug)
            && strlen($slug) >= 3
            && ! Hostname::isReservedSlug($slug);
    }

    public static function isAvailable(string $slug): bool
    {
        return self::isValid($slug)
            && ! Tenant::query()->where('slug', $slug)->exists()
            && ! Domain::query()->where('domain', Hostname::subdomainFor($slug))->exists();
    }

    /**
     * Generate an available slug from a business name ("ABC Salon" → "abc-salon", "abc-salon-2", ...).
     */
    public static function generate(string $name): string
    {
        $base = Str::of($name)->slug()->limit(55, '')->trim('-')->toString();

        if (strlen($base) < 3 || Hostname::isReservedSlug($base)) {
            $base = trim($base.'-business', '-');
        }

        $slug = $base;
        $suffix = 2;

        while (! self::isAvailable($slug)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;

            if ($suffix > 1000) {
                throw new InvalidArgumentException("Could not generate a slug for [{$name}].");
            }
        }

        return $slug;
    }
}
