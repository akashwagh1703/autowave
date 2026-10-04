<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class MarketingPagesTest extends TestCase
{
    use ManagesBilling, RefreshDatabase;

    public function test_pricing_lists_the_public_plans_from_the_database(): void
    {
        $this->get($this->marketingUrl('/pricing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('marketing/Pricing')
                ->has('plans', 3)
                ->where('plans.0.code', 'starter')
                ->where('plans.0.price_monthly', 49900)
                ->where('plans.1.limits.instagram', true)
                ->where('billing.trial_days', 14)
                ->where('billing.gst', false)
                ->where('appUrl', rtrim(config('app.url'), '/')));
    }

    public function test_policy_pages_show_the_company_details_from_billing_settings(): void
    {
        $this->configureBilling();
        $this->enableGst();
        config(['autowave.legal.jurisdiction' => 'Pune, Maharashtra']);

        foreach (['privacy' => 'Privacy', 'terms' => 'Terms', 'refunds' => 'Refunds', 'contact' => 'Contact'] as $path => $component) {
            $this->get($this->marketingUrl("/{$path}"))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component("marketing/{$component}")
                    ->where('company.name', 'AutoWave')
                    ->where('company.address', 'Pune, Maharashtra')
                    ->where('company.email', 'billing@autowave.test')
                    ->where('company.gstin', '27ABCDE1234F1Z5')
                    ->where('legal.jurisdiction', 'Pune, Maharashtra')
                    ->where('legal.updated', config('autowave.legal.updated'))
                    ->where('billing.grace_days', 7)
                    ->where('billing.lock_after_days', 30));
        }
    }

    public function test_company_details_fall_back_when_billing_settings_are_empty(): void
    {
        config(['mail.from.address' => 'hello@autowave.test']);

        $this->get($this->marketingUrl('/contact'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('company.email', 'hello@autowave.test')
                ->where('company.gstin', null)
                ->where('legal.jurisdiction', null));
    }

    public function test_policy_pages_are_only_on_the_marketing_host(): void
    {
        $this->get($this->appUrl('/privacy'))->assertNotFound();
    }

    public function test_app_pages_know_the_marketing_url_for_policy_links(): void
    {
        $this->get($this->appUrl('/register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('app.marketing_url', 'http://'.config('autowave.hosts.marketing')));
    }
}
