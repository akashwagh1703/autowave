<?php

namespace App\Domain\Website\Support;

/**
 * schema.org LocalBusiness data for a public website, so search engines can show the business's
 * name, address, phone and links in local results. Built from the same public props the page renders.
 */
class WebsiteSchema
{
    /** Business type preset → schema.org type. */
    private const TYPES = [
        'beauty_salon' => 'BeautySalon',
        'clinic' => 'MedicalClinic',
        'turf' => 'SportsActivityLocation',
        'coaching' => 'EducationalOrganization',
        'cafe' => 'CafeOrCoffeeShop',
        'local_store' => 'Store',
    ];

    /**
     * @param  array<string, mixed>  $page  WebsiteContent::page()
     * @return array<string, mixed>
     */
    public static function localBusiness(array $page, ?string $businessType, string $url): array
    {
        $business = $page['business'];
        $contact = $page['contact'];
        $image = $page['seo']['image'] ?? null;
        $origin = rtrim($url, '/');

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => self::TYPES[$businessType] ?? 'LocalBusiness',
            'name' => $business['name'],
            'description' => $page['seo']['description'] ?? null,
            'url' => $origin.'/',
            'logo' => $business['logo'] ? self::absolute($business['logo'], $origin) : null,
            'image' => $image ? self::absolute($image, $origin) : null,
            'telephone' => $contact['phone'] ?: null,
            'email' => $contact['email'] ?: null,
            'address' => filled($contact['address']) || filled($contact['city'])
                ? array_filter(['@type' => 'PostalAddress', 'streetAddress' => $contact['address'] ?: null, 'addressLocality' => $contact['city'] ?: null])
                : null,
            'hasMap' => $contact['map_url'],
            'sameAs' => array_column($page['social'], 'url') ?: null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private static function absolute(string $path, string $origin): string
    {
        return str_starts_with($path, 'http') ? $path : $origin.$path;
    }
}
