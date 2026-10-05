<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Marketing\Support\SalesWhatsApp;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marketing site pages: home, industries, pricing, demo and the policies payment providers and Meta ask
 * for. Company details come from Super Admin → Settings → Billing and the numbers from config('billing'),
 * so the text always matches how billing works. Search titles and descriptions are in config('marketing').
 */
class PageController extends Controller
{
    public function __construct(
        private readonly BillingSettings $billing,
        private readonly SalesWhatsApp $whatsapp,
    ) {}

    public function home(): Response
    {
        $plans = $this->plans();
        $from = $plans->min('price_monthly');

        return $this->page('Welcome', 'pages.home', ['startingPrice' => $from])->withViewData('schema', [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', 'name' => config('app.name'), 'url' => url('/'), 'logo' => url('/images/autowave-mark.png')],
                array_filter([
                    '@type' => 'SoftwareApplication',
                    'name' => config('app.name'),
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web',
                    'description' => config('marketing.pages.home.description'),
                    'offers' => $from !== null
                        ? ['@type' => 'AggregateOffer', 'priceCurrency' => 'INR', 'lowPrice' => number_format($from / 100, 2, '.', ''), 'offerCount' => $plans->count()]
                        : null,
                ]),
            ],
        ]);
    }

    public function industry(string $industry): Response
    {
        abort_unless(array_key_exists($industry, config('marketing.industries')), 404);

        return $this->page('marketing/Industry', "industries.{$industry}", [
            'industry' => $industry,
            'startingPrice' => $this->plans()->min('price_monthly'),
        ]);
    }

    public function pricing(): Response
    {
        return $this->page('marketing/Pricing', 'pages.pricing', ['plans' => $this->plans()]);
    }

    public function demo(Request $request): Response
    {
        return $this->page('marketing/Demo', 'pages.demo', [
            'industries' => collect(config('marketing.industries'))->pluck('name')->push('Other')->values(),
            'requested' => (bool) $request->session()->get('demo_requested'),
        ]);
    }

    public function privacy(): Response
    {
        return $this->page('marketing/Privacy', 'pages.privacy');
    }

    public function terms(): Response
    {
        return $this->page('marketing/Terms', 'pages.terms');
    }

    public function refunds(): Response
    {
        return $this->page('marketing/Refunds', 'pages.refunds');
    }

    public function contact(): Response
    {
        return $this->page('marketing/Contact', 'pages.contact');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function plans(): Collection
    {
        return Plan::query()->purchasable()->get()->map(fn (Plan $plan) => [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price_monthly' => $plan->price_monthly,
            'price_yearly' => $plan->price_yearly,
            'limits' => $plan->limits,
        ])->values();
    }

    /** @param  array<string, mixed>  $props */
    private function page(string $component, string $meta, array $props = []): Response
    {
        $meta = config("marketing.{$meta}");
        $seller = $this->billing->get('seller');

        return Inertia::render($component, [
            'meta' => ['title' => $meta['title'], 'description' => $meta['description']],
            'appUrl' => $this->appUrl(),
            'whatsappUrl' => $this->whatsapp->url(),
            'industryLinks' => collect(config('marketing.industries'))->map(fn (array $item, string $slug) => ['slug' => $slug, 'name' => $item['name']])->values(),
            'company' => [
                'name' => $seller['name'] ?: config('app.name'),
                'address' => $seller['address'],
                'email' => $seller['email'] ?: config('mail.from.address'),
                'phone' => $seller['phone'],
                'gstin' => $this->billing->gstEnabled() ? $this->billing->get('gst.gstin') : null,
            ],
            'legal' => [
                'updated' => config('autowave.legal.updated'),
                'jurisdiction' => config('autowave.legal.jurisdiction'),
                'grievance_officer' => config('autowave.legal.grievance_officer'),
                'refund_days' => (int) config('autowave.legal.refund_days'),
                'refund_request_days' => (int) config('autowave.legal.refund_request_days'),
            ],
            'billing' => [
                'trial_days' => (int) config('billing.trial.days'),
                'grace_days' => (int) config('billing.grace_days'),
                'lock_after_days' => (int) config('billing.lock_after_days'),
                'gst' => $this->billing->gstEnabled(),
                'gst_rate' => (int) config('billing.gst.rate'),
                'online' => $this->billing->onlineEnabled(),
                'manual' => $this->billing->manualEnabled(),
            ],
            ...$props,
        ]);
    }

    private function appUrl(): string
    {
        return rtrim(config('app.url'), '/');
    }
}
