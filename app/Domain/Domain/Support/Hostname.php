<?php

namespace App\Domain\Domain\Support;

use Illuminate\Support\Str;

/**
 * Hostname helpers shared by domain resolution, domain assignment and routing.
 */
class Hostname
{
    /**
     * Lowercase, strip port and trailing dot. Returns '' for unusable input.
     */
    public static function normalize(?string $host): string
    {
        $host = Str::of((string) $host)->trim()->lower()->toString();
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = rtrim($host, '.');

        if ($host === '' || strlen($host) > 253 || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return '';
        }

        return $host;
    }

    /** @return list<string> */
    public static function platformHosts(): array
    {
        $hosts = array_map(self::normalize(...), array_values(config('autowave.hosts')));
        $hosts[] = 'www.'.self::normalize(config('autowave.root_domain'));

        return array_values(array_unique(array_filter($hosts)));
    }

    public static function isPlatformHost(string $host): bool
    {
        return in_array(self::normalize($host), self::platformHosts(), true);
    }

    public static function subdomainFor(string $slug): string
    {
        return $slug.'.'.self::normalize(config('autowave.root_domain'));
    }

    public static function isReservedSlug(string $slug): bool
    {
        return in_array(strtolower($slug), config('autowave.reserved_subdomains', []), true);
    }
}
