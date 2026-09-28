<?php

namespace App\Http\Presenters;

use App\Domain\Media\Models\Media;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Support\SectionCatalog;

/** Website editor props for the business app. */
class WebsitePresenter
{
    /** Host-relative link to the tenant's website on its primary domain (keeps the dev port). */
    public static function siteUrl(Tenant $tenant): ?string
    {
        $domain = $tenant->loadMissing('primaryDomain')->primaryDomain?->domain;

        if ($domain === null) {
            return null;
        }

        $port = request()->getPort();

        return '//'.$domain.(in_array($port, [80, 443], true) ? '' : ':'.$port);
    }

    /** @return array<string, mixed> */
    public static function section(WebsiteSection $section, SectionCatalog $catalog, bool $live): array
    {
        $definition = SectionCatalog::definition($section->type) ?? [];

        return [
            'id' => $section->id,
            'type' => $section->type,
            'label' => $definition['label'] ?? $section->type,
            'description' => $definition['description'] ?? null,
            'enabled' => $section->enabled,
            'available' => $catalog->isAvailable($section->type),
            'removable' => SectionCatalog::isRemovable($section->type),
            'pinned' => SectionCatalog::position($section->type),
            'editable' => $catalog->fields($section->type) !== [] || isset($definition['media']),
            'live' => $live,
            'empty_hint' => $definition['empty_hint'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public static function media(Media $media): array
    {
        return [
            'id' => $media->id,
            'collection' => $media->collection,
            'url' => $media->url(),
            'alt' => $media->alt,
            'original_name' => $media->original_name,
            'width' => $media->width,
            'height' => $media->height,
            'size_bytes' => $media->size_bytes,
        ];
    }

    /** @return array<string, mixed> upload rules for a media collection, for client-side hints */
    public static function mediaRules(string $collection): array
    {
        $limits = config('website.media');

        return [
            'collection' => $collection,
            'label' => $limits['collections'][$collection]['label'],
            'max' => $limits['collections'][$collection]['max'],
            'max_kb' => $limits['max_kb'],
            'accept' => implode(',', array_map(fn (string $ext) => '.'.$ext, $limits['mimes'])),
            'min_dimension' => $limits['min_dimension'],
        ];
    }
}
