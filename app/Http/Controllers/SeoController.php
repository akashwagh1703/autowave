<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Support\Entitlements;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * robots.txt and sitemap.xml per host: the marketing site and live tenant websites are open to search
 * engines; the app and admin hosts, draft sites and locked sites are not.
 */
class SeoController extends Controller
{
    public function marketingRobots(Request $request): Response
    {
        return $this->robots(true, $request->getSchemeAndHttpHost().'/sitemap.xml');
    }

    public function marketingSitemap(Request $request): Response
    {
        $paths = ['/', '/pricing', '/demo', ...array_map(fn (string $slug) => "/for/{$slug}", array_keys(config('marketing.industries'))), '/contact', '/terms', '/privacy', '/refunds'];

        return $this->sitemap(array_map(fn (string $path) => ['loc' => $request->getSchemeAndHttpHost().$path], $paths));
    }

    public function closedRobots(): Response
    {
        return $this->robots(false);
    }

    public function siteRobots(Request $request, TenantContext $context, Entitlements $entitlements): Response
    {
        return $this->siteIsLive($context, $entitlements)
            ? $this->robots(true, $request->getSchemeAndHttpHost().'/sitemap.xml', ['/preview'])
            : $this->robots(false);
    }

    public function siteSitemap(Request $request, TenantContext $context, Entitlements $entitlements): Response
    {
        abort_unless($this->siteIsLive($context, $entitlements), 404);

        $updated = WebsiteConfig::query()->value('updated_at');

        return $this->sitemap([['loc' => $request->getSchemeAndHttpHost().'/', 'lastmod' => $updated ? substr((string) $updated, 0, 10) : null]]);
    }

    private function siteIsLive(TenantContext $context, Entitlements $entitlements): bool
    {
        return $context->hasModule('website')
            && (bool) WebsiteConfig::query()->first()?->isPublished()
            && $entitlements->websiteOnline($context->tenant());
    }

    /** @param  list<string>  $disallow */
    private function robots(bool $open, ?string $sitemap = null, array $disallow = []): Response
    {
        $lines = ['User-agent: *'];

        if ($open) {
            $lines = [...$lines, ...($disallow === [] ? ['Allow: /'] : array_map(fn (string $path) => "Disallow: {$path}", $disallow))];
            $lines = $sitemap ? [...$lines, '', "Sitemap: {$sitemap}"] : $lines;
        } else {
            $lines[] = 'Disallow: /';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @param  list<array{loc: string, lastmod?: ?string}>  $urls */
    private function sitemap(array $urls): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc>'.(! empty($url['lastmod']) ? '<lastmod>'.e($url['lastmod']).'</lastmod>' : '').'</url>'."\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
