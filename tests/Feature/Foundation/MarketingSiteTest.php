<?php

namespace Tests\Feature\Foundation;

use App\Domain\Activity\Models\Activity;
use App\Domain\Lead\Models\Lead;
use App\Domain\Marketing\Models\DemoRequest;
use App\Domain\Marketing\Notifications\DemoRequested;
use App\Domain\Website\Actions\UpdateWebsiteSettings;
use App\Domain\Website\Support\WebsitePreview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ManagesBilling;
use Tests\TestCase;

class MarketingSiteTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, ManagesBilling, RefreshDatabase;

    private const SALON = 'abc-salon.autowave.test';

    public function test_the_home_page_renders_search_and_preview_tags_on_the_server(): void
    {
        $response = $this->get($this->marketingUrl('/'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('meta.title', config('marketing.pages.home.title'))
                ->where('startingPrice', 49900)
                ->has('industryLinks', count(config('marketing.industries'))));

        $title = e(config('marketing.pages.home.title').' · '.config('app.name'));
        $response->assertSee("<title inertia>{$title}</title>", false)
            ->assertSee('<meta name="description" content="'.e(config('marketing.pages.home.description')).'">', false)
            ->assertSee('<link rel="canonical" href="'.$this->marketingUrl('').'">', false)
            ->assertSee('property="og:image" content="'.$this->marketingUrl('/images/og-autowave.png').'"', false)
            ->assertSee('"@type":"SoftwareApplication"', false)
            ->assertSee('"lowPrice":"499.00"', false);
    }

    public function test_every_industry_page_has_its_own_title_and_unknown_ones_are_not_found(): void
    {
        foreach (config('marketing.industries') as $slug => $industry) {
            $this->get($this->marketingUrl("/for/{$slug}"))
                ->assertOk()
                ->assertSee('<title inertia>'.e($industry['title']).' · '.config('app.name').'</title>', false)
                ->assertInertia(fn (Assert $page) => $page->component('marketing/Industry')->where('industry', $slug)->where('meta.description', $industry['description']));
        }

        $this->get($this->marketingUrl('/for/spaceships'))->assertNotFound();
    }

    public function test_the_whatsapp_link_uses_the_sales_number(): void
    {
        config(['marketing.whatsapp' => '+91 98765 43210']);

        $this->get($this->marketingUrl('/demo'))->assertInertia(fn (Assert $page) => $page
            ->where('whatsappUrl', fn (string $url) => str_starts_with($url, 'https://wa.me/919876543210?text='))
            ->where('requested', false)
            ->where('industries', fn ($industries) => collect($industries)->contains('Salons & spas') && collect($industries)->last() === 'Other'));

        config(['marketing.whatsapp' => null]);
        $this->get($this->marketingUrl('/demo'))->assertInertia(fn (Assert $page) => $page->where('whatsappUrl', null));
    }

    public function test_robots_and_sitemap_open_the_marketing_site_and_close_the_app_and_admin(): void
    {
        $robots = $this->get($this->marketingUrl('/robots.txt'))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('Allow: /', $robots->getContent());
        $this->assertStringContainsString('Sitemap: '.$this->marketingUrl('/sitemap.xml'), $robots->getContent());

        $sitemap = $this->get($this->marketingUrl('/sitemap.xml'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        foreach (['/', '/pricing', '/demo', '/for/salons', '/for/stores', '/privacy'] as $path) {
            $this->assertStringContainsString('<loc>'.$this->marketingUrl($path).'</loc>', $sitemap);
        }

        foreach ([$this->appUrl('/robots.txt'), $this->adminUrl('/robots.txt')] as $url) {
            $this->assertSame("User-agent: *\nDisallow: /\n", $this->get($url)->assertOk()->getContent());
        }
    }

    public function test_live_business_sites_are_open_to_search_engines_and_drafts_are_not(): void
    {
        $tenant = $this->createTenant();

        $robots = $this->get($this->siteUrl(self::SALON, '/robots.txt'))->assertOk()->getContent();
        $this->assertStringContainsString('Disallow: /preview', $robots);
        $this->assertStringContainsString('Sitemap: http://'.self::SALON.'/sitemap.xml', $robots);
        $this->assertStringContainsString('<loc>http://'.self::SALON.'/</loc>', $this->get($this->siteUrl(self::SALON, '/sitemap.xml'))->assertOk()->getContent());

        $this->inTenant($tenant, fn () => app(UpdateWebsiteSettings::class)->publish(false));

        $this->assertSame("User-agent: *\nDisallow: /\n", $this->get($this->siteUrl(self::SALON, '/robots.txt'))->getContent());
        $this->get($this->siteUrl(self::SALON, '/sitemap.xml'))->assertNotFound();
    }

    public function test_business_sites_describe_the_business_for_search_engines(): void
    {
        $tenant = $this->createTenant();
        $this->inTenant($tenant, fn () => app(UpdateWebsiteSettings::class)->details([
            'business_name' => 'ABC Salon',
            'tagline' => 'Hair, skin and bridal studio',
            'description' => null,
            'phone' => '+91 98765 43210',
            'whatsapp' => null,
            'email' => 'hello@abc-salon.test',
            'address' => '12 MG Road',
            'city' => 'Pune',
            'opening_hours' => null,
            'social' => ['instagram' => 'https://instagram.com/abcsalon'],
        ]));

        $this->get($this->siteUrl(self::SALON))
            ->assertOk()
            ->assertSee('"@type":"BeautySalon"', false)
            ->assertSee('"name":"ABC Salon"', false)
            ->assertSee('"telephone":"+91 98765 43210"', false)
            ->assertSee('"address":{"@type":"PostalAddress","streetAddress":"12 MG Road","addressLocality":"Pune"}', false)
            ->assertSee('"sameAs":["https://instagram.com/abcsalon"]', false)
            ->assertSee('<link rel="canonical" href="http://'.self::SALON.'/">', false);

        // Draft previews are not described to search engines.
        $this->inTenant($tenant, fn () => app(UpdateWebsiteSettings::class)->publish(false));
        $this->get('http:'.WebsitePreview::url($tenant, '//'.self::SALON))
            ->assertOk()
            ->assertDontSee('application/ld+json', false)
            ->assertDontSee('rel="canonical"', false);
    }

    public function test_a_demo_request_is_saved_emailed_to_admins_and_becomes_a_website_lead_of_the_internal_tenant(): void
    {
        Notification::fake();
        $admin = User::factory()->platformAdmin()->create();
        $internal = $this->createTenant('AutoWave Internal', 'autowave_internal', options: ['is_internal' => true]);
        $salon = $this->createTenant();

        $this->from($this->marketingUrl('/demo'))->post($this->marketingUrl('/demo'), [
            'name' => 'Asha Patil',
            'phone' => '98765 43210',
            'email' => 'asha@example.com',
            'business_name' => 'Asha Beauty Lounge',
            'industry' => 'Salons & spas',
            'city' => 'Nashik',
            'message' => 'Two branches, five stylists.',
        ])->assertSessionHasNoErrors()->assertRedirect($this->marketingUrl('/demo'))->assertSessionHas('demo_requested', true);

        $demo = DemoRequest::query()->sole();
        $this->assertSame(['Asha Patil', '+919876543210', 'Asha Beauty Lounge', 'Salons & spas', 'Nashik', 'Two branches, five stylists.', DemoRequest::NEW], [
            $demo->name, $demo->phone, $demo->business_name, $demo->industry, $demo->city, $demo->message, $demo->status,
        ]);
        Notification::assertSentTo($admin, DemoRequested::class, function (DemoRequested $notification) use ($admin, $demo) {
            $mail = $notification->toMail($admin);

            return $notification->demoRequestId === $demo->id
                && $mail->subject === 'Demo request: Asha Beauty Lounge'
                && in_array('Phone: +919876543210', $mail->introLines, true)
                && $mail->actionUrl === route('admin.demo-requests.index');
        });

        $this->inTenant($internal, function () use ($demo) {
            $lead = Lead::query()->with('source')->sole();
            $this->assertSame($lead->id, $demo->lead_id);
            $this->assertSame('Asha Patil', $lead->name);
            $this->assertSame('website', $lead->source?->code);
            $this->assertSame('Demo: Salons & spas', $lead->interest);

            $activity = Activity::query()->where('lead_id', $lead->id)->where('type', 'website_enquiry')->sole();
            $this->assertSame("Business: Asha Beauty Lounge\nCity: Nashik\n\nTwo branches, five stylists.", $activity->body);
        });
        $this->assertSame(0, $this->inTenant($salon, fn () => Lead::query()->count()));

        // The thank-you message shows once, after the redirect.
        $this->get($this->marketingUrl('/demo'))->assertInertia(fn (Assert $page) => $page->where('requested', true));
        $this->get($this->marketingUrl('/demo'))->assertInertia(fn (Assert $page) => $page->where('requested', false));
    }

    public function test_demo_requests_are_validated_and_bots_are_ignored(): void
    {
        $internal = $this->createTenant('AutoWave Internal', 'autowave_internal', options: ['is_internal' => true]);

        $this->post($this->marketingUrl('/demo'), ['name' => 'A', 'phone' => 'call me', 'industry' => 'Spaceships'])
            ->assertSessionHasErrors(['name', 'phone', 'business_name', 'industry']);

        $this->post($this->marketingUrl('/demo'), ['name' => 'Bot', 'phone' => '98765 43210', 'business_name' => 'Spam', 'company_website' => 'https://spam.test'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('demo_requested', true);

        $this->assertSame(0, $this->inTenant($internal, fn () => Lead::query()->count()));
        $this->assertSame(0, DemoRequest::query()->count());
    }

    public function test_a_demo_request_without_the_internal_tenant_is_still_saved_and_emailed(): void
    {
        Notification::fake();
        $admin = User::factory()->platformAdmin()->create();

        $this->post($this->marketingUrl('/demo'), ['name' => 'Asha Patil', 'phone' => '98765 43210', 'business_name' => 'Asha Beauty Lounge'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('demo_requested', true);

        $this->assertNull(DemoRequest::query()->sole()->lead_id);
        Notification::assertSentTo($admin, DemoRequested::class);
    }

    public function test_platform_admins_follow_up_demo_requests_in_super_admin(): void
    {
        $admin = User::factory()->platformAdmin()->create(['name' => 'Akash']);
        $fresh = DemoRequest::query()->create(['name' => 'Asha Patil', 'phone' => '+919876543210', 'business_name' => 'Asha Beauty Lounge', 'city' => 'Nashik']);
        DemoRequest::query()->create(['name' => 'Ravi', 'phone' => '+919812345678', 'business_name' => 'Ravi Turf', 'status' => DemoRequest::CLOSED]);

        $this->get($this->adminUrl('/demo-requests'))->assertRedirect();
        $this->actingAs(User::factory()->create())->get($this->adminUrl('/demo-requests'))->assertForbidden();

        $this->actingAs($admin);
        $this->get($this->adminUrl('/'))->assertInertia(fn (Assert $page) => $page->where('stats.new_demo_requests', 1));
        $this->get($this->adminUrl('/demo-requests'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/marketing/DemoRequests')
            ->where('filters.status', 'new')
            ->has('requests.data', 1)
            ->where('requests.data.0.business_name', 'Asha Beauty Lounge')
            ->where('counts.new', 1)
            ->where('counts.closed', 1));
        $this->get($this->adminUrl('/demo-requests?status=all&search=98123'))->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.business_name', 'Ravi Turf'));
        $this->get($this->adminUrl('/demo-requests?status=all&search=nashik'))->assertInertia(fn (Assert $page) => $page->has('requests.data', 1));

        $this->put($this->adminUrl("/demo-requests/{$fresh->id}"), ['status' => 'contacted', 'note' => ' Demo on Friday 4 pm. '])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $fresh->refresh();
        $this->assertSame([DemoRequest::CONTACTED, 'Demo on Friday 4 pm.', $admin->id], [$fresh->status, $fresh->note, $fresh->handled_by_user_id]);
        $this->assertNotNull($fresh->handled_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.demo_request_updated', 'subject_id' => $fresh->id]);
        $this->get($this->adminUrl('/'))->assertInertia(fn (Assert $page) => $page->where('stats.new_demo_requests', 0));

        $this->put($this->adminUrl("/demo-requests/{$fresh->id}"), ['status' => 'maybe'])->assertSessionHasErrors('status');

        $this->delete($this->adminUrl("/demo-requests/{$fresh->id}"))->assertSessionHas('success');
        $this->assertModelMissing($fresh);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.demo_request_deleted']);
    }
}
