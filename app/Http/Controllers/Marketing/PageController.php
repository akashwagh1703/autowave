<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Support\BillingSettings;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marketing site pages: pricing and the policies payment providers and Meta ask for. Company details come
 * from Super Admin → Settings → Billing and the numbers from config('billing'), so the text always matches
 * how billing works.
 */
class PageController extends Controller
{
    public function __construct(private readonly BillingSettings $billing) {}

    public function home(): Response
    {
        return Inertia::render('Welcome', ['appUrl' => $this->appUrl()]);
    }

    public function pricing(): Response
    {
        return $this->page('marketing/Pricing', [
            'plans' => Plan::query()->purchasable()->get()->map(fn (Plan $plan) => [
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'price_monthly' => $plan->price_monthly,
                'price_yearly' => $plan->price_yearly,
                'limits' => $plan->limits,
            ])->values(),
        ]);
    }

    public function privacy(): Response
    {
        return $this->page('marketing/Privacy');
    }

    public function terms(): Response
    {
        return $this->page('marketing/Terms');
    }

    public function refunds(): Response
    {
        return $this->page('marketing/Refunds');
    }

    public function contact(): Response
    {
        return $this->page('marketing/Contact');
    }

    /** @param  array<string, mixed>  $props */
    private function page(string $component, array $props = []): Response
    {
        $seller = $this->billing->get('seller');

        return Inertia::render($component, [
            'appUrl' => $this->appUrl(),
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
