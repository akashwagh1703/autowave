<?php

namespace App\Domain\Website\Support;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Signed links that show an unpublished website to its team. The signature covers the path and
 * query only (the tenant host differs from the app host that signs it), so the tenant id is part
 * of the signed query and must match the tenant resolved from the host.
 */
class WebsitePreview
{
    public static function url(Tenant $tenant, string $siteUrl): string
    {
        $path = URL::temporarySignedRoute('tenant.preview', now()->addMinutes((int) config('website.preview_minutes')), ['preview' => $tenant->id], absolute: false);

        return rtrim($siteUrl, '/').$path;
    }

    public static function isValid(Request $request, Tenant $tenant): bool
    {
        return $request->query('preview') !== null
            && (int) $request->query('preview') === $tenant->id
            && $request->hasValidRelativeSignature();
    }
}
