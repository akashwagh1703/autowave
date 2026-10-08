<?php

namespace Tests\Feature\Website;

use App\Domain\Media\Actions\ManageMedia;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Actions\UpdateWebsiteSettings;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Support\WebsitePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesBookingRecords;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use CreatesBookingRecords, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const SALON = 'abc-salon.autowave.test';

    private function types(array $sections): array
    {
        return array_column($sections, 'type');
    }

    private function section(array $sections, string $type): ?array
    {
        return collect($sections)->firstWhere('type', $type);
    }

    private function details(Tenant $tenant, array $values = []): void
    {
        $this->inTenant($tenant, fn () => app(UpdateWebsiteSettings::class)->details([
            'business_name' => 'ABC Salon',
            'tagline' => 'Hair, skin and bridal studio',
            'description' => 'Family salon since 2010.',
            'phone' => '+91 98765 43210',
            'whatsapp' => '98765 43210',
            'email' => 'hello@abc-salon.test',
            'address' => '12 MG Road',
            'city' => 'Pune',
            'opening_hours' => 'Mon–Sat 10–8',
            'social' => ['instagram' => 'https://instagram.com/abcsalon'],
            ...$values,
        ]));
    }

    public function test_the_site_shows_business_data_read_from_the_database(): void
    {
        $this->travelToBookingDay();
        $tenant = $this->createTenant();
        $this->details($tenant);
        $stylist = $this->makeResource($tenant, ['name' => 'Sana', 'description' => 'Senior stylist']);
        $this->makeService($tenant, ['name' => 'Haircut', 'price' => 450, 'duration_minutes' => 45], [$stylist]);
        $this->makeService($tenant, ['name' => 'Retired service', 'is_active' => false], [$stylist]);

        $this->get($this->siteUrl(self::SALON))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('website/Home')
                ->where('business.name', 'ABC Salon')
                ->where('business.tagline', 'Hair, skin and bridal studio')
                ->where('contact.phone_href', 'tel:+919876543210')
                ->where('contact.whatsapp_url', fn (string $url) => str_starts_with($url, 'https://wa.me/919876543210?text=') && str_contains(urldecode($url), 'Hi ABC Salon'))
                ->where('contact.map_url', fn (string $url) => str_contains(urldecode($url), 'ABC Salon, 12 MG Road, Pune'))
                ->where('social.0.url', 'https://instagram.com/abcsalon')
                ->where('sections', function ($sections) {
                    $sections = collect($sections)->all();
                    $services = $this->section($sections, 'services')['data'];
                    $team = $this->section($sections, 'team')['data'];

                    $this->assertSame(['Haircut'], collect($services)->pluck('services')->flatten(1)->pluck('name')->all());
                    $this->assertSame(450.0, (float) $services[0]['services'][0]['price']);
                    $this->assertTrue($services[0]['services'][0]['bookable']);
                    $this->assertSame('Sana', $team[0]['name']);
                    $this->assertSame(['Haircut'], $team[0]['services']);
                    $this->assertSame('header', $sections[0]['type']);
                    $this->assertSame('footer', end($sections)['type']);

                    return true;
                })
                ->where('booking.services.0.name', 'Haircut')
                ->where('enquiry.enabled', true)
                ->where('enquiry.interests', ['Haircut']));
    }

    public function test_packages_appear_on_the_site_and_stay_out_of_the_services_section(): void
    {
        $this->travelToBookingDay();
        $tenant = $this->createTenant();
        $this->details($tenant);
        $stylist = $this->makeResource($tenant, ['name' => 'Sana']);
        $haircut = $this->makeService($tenant, ['name' => 'Haircut', 'price' => 450], [$stylist]);
        $this->makeService($tenant, [
            'name' => 'Bridal package',
            'price' => 15000,
            'duration_minutes' => 180,
            'is_package' => true,
            'included_service_ids' => [$haircut->id],
            'resource_ids' => [$stylist->id],
        ], [$stylist]);

        $this->get($this->siteUrl(self::SALON))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sections', function ($sections) {
                $sections = collect($sections)->all();
                $services = collect($this->section($sections, 'services')['data'])->pluck('services')->flatten(1)->pluck('name')->all();
                $packages = $this->section($sections, 'packages')['data'];

                $this->assertSame(['Haircut'], $services);
                $this->assertSame('Bridal package', $packages[0]['name']);
                $this->assertSame(['Haircut'], $packages[0]['includes']);
                $this->assertTrue($packages[0]['bookable']);

                return true;
            })->where('booking.services', function ($services) {
                $names = collect($services)->pluck('name')->all();
                $this->assertContains('Haircut', $names);
                $this->assertContains('Bridal package', $names);

                return true;
            }));
    }

    public function test_sections_without_content_are_hidden_including_products_until_commerce(): void
    {
        $tenant = $this->createTenant();

        $this->inTenant($tenant, function () {
            $this->assertTrue(WebsiteSection::query()->where('type', 'products')->sole()->enabled);
            $this->assertTrue(WebsiteSection::query()->where('type', 'packages')->sole()->enabled);
        });

        $this->get($this->siteUrl(self::SALON))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sections', function ($sections) {
                $types = $this->types(collect($sections)->all());

                foreach (['products', 'packages', 'services', 'team', 'gallery', 'testimonials', 'offers', 'faq', 'booking'] as $hidden) {
                    $this->assertNotContains($hidden, $types, "{$hidden} has no content yet and must stay hidden.");
                }
                $this->assertContains('contact', $types);

                return true;
            })->where('booking', null));

        $this->inTenant($tenant, fn () => WebsiteSection::query()->where('type', 'faq')->update([
            'configuration' => ['items' => [['question' => 'Do you take walk-ins?', 'answer' => 'Yes, until 7 pm.']]],
        ]));

        $this->get($this->siteUrl(self::SALON))
            ->assertInertia(fn (Assert $page) => $page->where('sections', fn ($sections) => $this->section(collect($sections)->all(), 'faq')['data'][0]['question'] === 'Do you take walk-ins?'));
    }

    public function test_seo_tags_are_rendered_on_the_server(): void
    {
        $tenant = $this->createTenant();
        $this->details($tenant, ['seo_title' => 'Best salon in Pune', 'seo_description' => 'Haircuts & bridal makeup in Pune.']);

        $this->get($this->siteUrl(self::SALON))
            ->assertOk()
            ->assertSee('<title inertia>Best salon in Pune</title>', false)
            ->assertSee('<meta name="description" content="Haircuts &amp; bridal makeup in Pune." inertia="description">', false)
            ->assertSee('property="og:title" content="Best salon in Pune"', false)
            ->assertSee('data-site="tenant"', false)
            ->assertDontSee('noindex', false);

        // Without SEO overrides the business name and tagline are used.
        $this->details($tenant, ['seo_title' => null, 'seo_description' => null]);

        $this->get($this->siteUrl(self::SALON))
            ->assertSee('<title inertia>ABC Salon</title>', false)
            ->assertSee('content="Hair, skin and bridal studio" inertia="description"', false);
    }

    public function test_the_logo_and_gallery_come_from_uploaded_media(): void
    {
        Storage::fake('public');
        $tenant = $this->createTenant();

        $this->inTenant($tenant, function () {
            app(ManageMedia::class)->upload(UploadedFile::fake()->image('logo.png', 400, 200), 'logo');
            app(ManageMedia::class)->upload(UploadedFile::fake()->image('shop.jpg', 800, 600), 'gallery', null, 'Our shop floor');
        });

        $this->get($this->siteUrl(self::SALON))
            ->assertInertia(fn (Assert $page) => $page
                ->where('business.logo', fn (string $url) => str_starts_with($url, "/storage/tenant/{$tenant->id}/logo/logo-"))
                ->where('seo.image', fn (string $url) => str_contains($url, '/logo/'))
                ->where('sections', function ($sections) {
                    $gallery = $this->section(collect($sections)->all(), 'gallery')['data'];

                    return count($gallery) === 1 && $gallery[0]['alt'] === 'Our shop floor' && $gallery[0]['width'] === 800;
                }));
    }

    public function test_draft_sites_are_offline_but_open_through_a_signed_preview_link(): void
    {
        $salon = $this->createTenant();
        $turf = $this->createTenant('Green Turf', 'turf');

        foreach ([$salon, $turf] as $tenant) {
            $this->inTenant($tenant, fn () => app(UpdateWebsiteSettings::class)->publish(false));
        }

        $this->get($this->siteUrl(self::SALON))->assertNotFound();

        $preview = WebsitePreview::url($salon, '//'.self::SALON);
        $this->assertStringStartsWith('//'.self::SALON.'/preview?', $preview);

        $this->get('http:'.$preview)
            ->assertOk()
            ->assertSee('noindex', false)
            ->assertInertia(fn (Assert $page) => $page->where('preview', true)->where('business.name', 'ABC Salon'));

        // The salon's link does not open the turf's draft site.
        $this->get('http:'.str_replace(self::SALON, 'green-turf.autowave.test', $preview))->assertNotFound();

        // Tampered and expired links are refused.
        $this->get('http:'.str_replace('preview='.$salon->id, 'preview='.$turf->id, $preview))->assertNotFound();
        $this->travel(config('website.preview_minutes') + 1)->minutes();
        $this->get('http:'.$preview)->assertNotFound();
    }

    public function test_published_sites_ignore_preview_parameters(): void
    {
        $this->createTenant();

        $this->get($this->siteUrl(self::SALON, '/?preview=1'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('preview', false));

        // An unsigned preview URL is refused even for a published site.
        $this->get($this->siteUrl(self::SALON, '/preview?preview=1'))->assertNotFound();
    }

    public function test_the_site_is_offline_without_the_website_module(): void
    {
        $tenant = $this->createTenant();
        $this->inTenant($tenant, fn () => WebsiteConfig::query()->update(['status' => 'published']));
        $this->disableModule($tenant, 'website');

        $this->get($this->siteUrl(self::SALON))->assertNotFound();
        $this->post($this->siteUrl(self::SALON, '/enquiry'), ['name' => 'Asha', 'phone' => '9876543210'])->assertNotFound();
    }

    public function test_turf_sites_offer_booking_without_services(): void
    {
        $this->travelToBookingDay();
        $turf = $this->createTenant('Green Turf', 'turf');
        $this->makeResource($turf, ['name' => 'Turf A']);

        $this->get($this->siteUrl('green-turf.autowave.test'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('booking.uses_services', false)
                ->where('booking.resources.0.name', 'Turf A')
                ->where('booking.resource_label.singular', 'Turf')
                ->where('sections', fn ($sections) => ! in_array('services', $this->types(collect($sections)->all()), true)));
    }
}
