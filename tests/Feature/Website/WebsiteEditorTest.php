<?php

namespace Tests\Feature\Website;

use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WebsiteEditorTest extends TestCase
{
    use CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private function sectionId(Tenant $tenant, string $type): int
    {
        return $this->inTenant($tenant, fn () => WebsiteSection::query()->where('type', $type)->value('id'));
    }

    private function sectionConfig(Tenant $tenant, string $type): ?array
    {
        return $this->inTenant($tenant, fn () => WebsiteSection::query()->where('type', $type)->value('configuration'));
    }

    public function test_the_overview_lists_sections_status_and_checklist(): void
    {
        $tenant = $this->createTenant();

        $this->actingAs($this->ownerOf($tenant))
            ->get($this->appUrl('/website'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/website/Index')
                ->where('website.status', 'published')
                ->where('website.url', '//abc-salon.autowave.test')
                ->where('website.preview_url', null)
                ->where('sections.0.type', 'header')
                ->where('sections', function ($sections) {
                    $sections = collect($sections);
                    $this->assertSame('footer', $sections->last()['type']);
                    $products = $sections->firstWhere('type', 'products');
                    $this->assertTrue($products['enabled']);
                    $this->assertFalse($products['live']);
                    $this->assertStringContainsString('Add active products', $products['empty_hint']);
                    $this->assertTrue($sections->firstWhere('type', 'contact')['live']);
                    $this->assertFalse($sections->firstWhere('type', 'header')['removable']);

                    return true;
                })
                ->where('addable', fn ($addable) => collect($addable)->pluck('type')->all() === ['reviews'])
                ->where('checklist', fn ($checklist) => collect($checklist)->firstWhere('key', 'published')['done'] === true)
                ->where('onlineBooking.settings.enabled', true)
                ->where('canManage', true));
    }

    public function test_a_draft_website_offers_a_preview_link(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->put($this->appUrl('/website/publish'), ['published' => false])->assertSessionHasNoErrors()->assertRedirect();

        $this->get($this->appUrl('/website'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('website.status', 'draft')
                ->where('website.preview_url', fn (string $url) => str_starts_with($url, '//abc-salon.autowave.test/preview?')));

        $this->get($this->siteUrl('abc-salon.autowave.test'))->assertNotFound();

        $this->put($this->appUrl('/website/publish'), ['published' => true])->assertSessionHasNoErrors();
        $this->get($this->siteUrl('abc-salon.autowave.test'))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'website.unpublished']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'website.published']);
    }

    public function test_sections_are_edited_through_their_schema(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $hero = $this->sectionId($tenant, 'hero');

        $this->get($this->appUrl("/website/sections/{$hero}/edit"))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('business/website/SectionEdit')
                ->where('fields', fn ($fields) => collect($fields)->pluck('key')->all() === ['headline', 'subheadline', 'cta'])
                ->where('media.rules.collection', 'hero'));

        $this->put($this->appUrl("/website/sections/{$hero}"), ['config' => [
            'headline' => '  Look your best  ',
            'subheadline' => '',
            'cta' => 'whatsapp',
            'injected' => '<script>',
        ]])->assertSessionHasNoErrors()->assertRedirect();

        $config = $this->sectionConfig($tenant, 'hero');
        $this->assertSame('Look your best', $config['headline']);
        $this->assertNull($config['subheadline']);
        $this->assertSame('whatsapp', $config['cta']);
        $this->assertArrayNotHasKey('injected', $config);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'website.section_updated']);

        $this->put($this->appUrl("/website/sections/{$hero}"), ['config' => ['headline' => str_repeat('a', 121), 'cta' => 'fax']])
            ->assertSessionHasErrors(['config.headline', 'config.cta']);
    }

    public function test_list_fields_are_validated_item_by_item(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $faq = $this->sectionId($tenant, 'faq');

        $this->put($this->appUrl("/website/sections/{$faq}"), ['config' => ['items' => [
            ['question' => 'Parking?', 'answer' => 'Free parking behind the salon.', 'extra' => 'x'],
            ['question' => '', 'answer' => 'No question'],
        ]]])->assertSessionHasErrors('config.items.1.question');

        $this->put($this->appUrl("/website/sections/{$faq}"), ['config' => ['items' => array_fill(0, 21, ['question' => 'Q', 'answer' => 'A'])]])
            ->assertSessionHasErrors('config.items');

        $this->put($this->appUrl("/website/sections/{$faq}"), ['config' => ['heading' => 'Questions', 'items' => [
            ['question' => 'Parking?', 'answer' => 'Free parking behind the salon.', 'extra' => 'x'],
        ]]])->assertSessionHasNoErrors();

        // jsonb does not keep key order.
        $this->assertEquals([['question' => 'Parking?', 'answer' => 'Free parking behind the salon.']], $this->sectionConfig($tenant, 'faq')['items']);
    }

    public function test_sections_can_be_added_hidden_reordered_and_removed(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->post($this->appUrl('/website/sections'), ['type' => 'reviews'])->assertRedirect();
        $this->post($this->appUrl('/website/sections'), ['type' => 'reviews'])->assertSessionHasErrors('type');
        $this->post($this->appUrl('/website/sections'), ['type' => 'nonsense'])->assertSessionHasErrors('type');

        $faq = $this->sectionId($tenant, 'faq');
        $this->patch($this->appUrl("/website/sections/{$faq}/toggle"), ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->inTenant($tenant, fn () => WebsiteSection::query()->find($faq)->enabled));

        $ids = $this->inTenant($tenant, fn () => WebsiteSection::query()->orderByDesc('sort_order')->pluck('id')->all());
        $this->put($this->appUrl('/website/sections/order'), ['ids' => $ids])->assertSessionHasNoErrors();
        $this->put($this->appUrl('/website/sections/order'), ['ids' => array_slice($ids, 1)])->assertSessionHasErrors('ids');

        // Header and footer stay pinned whatever the stored order.
        $this->get($this->siteUrl('abc-salon.autowave.test'))
            ->assertInertia(fn (Assert $page) => $page->where('sections', function ($sections) {
                $types = collect($sections)->pluck('type');

                return $types->first() === 'header' && $types->last() === 'footer' && $types->search('contact') < $types->search('hero');
            }));

        $header = $this->sectionId($tenant, 'header');
        $this->delete($this->appUrl("/website/sections/{$header}"))->assertSessionHasErrors('section');

        $this->delete($this->appUrl("/website/sections/{$faq}"))->assertRedirect($this->appUrl('/website'));
        $this->assertNull($this->sectionConfig($tenant, 'faq'));
        $this->post($this->appUrl('/website/sections'), ['type' => 'faq'])->assertSessionHasNoErrors();

        foreach (['website.section_added', 'website.section_hidden', 'website.sections_reordered', 'website.section_removed'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => $action]);
        }
    }

    public function test_sections_outside_the_business_engines_cannot_be_added(): void
    {
        $turf = $this->createTenant('Green Turf', 'turf');
        $this->actingAs($this->ownerOf($turf));

        $this->post($this->appUrl('/website/sections'), ['type' => 'services'])->assertSessionHasErrors('type');
        $this->post($this->appUrl('/website/sections'), ['type' => 'products'])->assertSessionHasErrors('type');
        $this->post($this->appUrl('/website/sections'), ['type' => 'testimonials'])->assertSessionHasNoErrors();
    }

    public function test_design_and_business_details_are_saved(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/website/design'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('business/website/Design')->has('templates'));
        $this->put($this->appUrl('/website/design'), ['template' => 'minimal', 'primary_color' => '#DB2777'])->assertSessionHasNoErrors();
        $this->put($this->appUrl('/website/design'), ['template' => 'nope', 'primary_color' => 'pink'])->assertSessionHasErrors(['template', 'primary_color']);

        $this->get($this->appUrl('/website/details'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('details.business_name', 'ABC Salon'));
        $this->put($this->appUrl('/website/details'), [
            'business_name' => 'ABC Unisex Salon',
            'tagline' => 'Style for everyone',
            'phone' => '+91 98765 43210',
            'whatsapp' => '+91 98765 43210',
            'opening_hours' => 'Daily 10–9',
            'social' => ['instagram' => 'https://instagram.com/abc', 'facebook' => ''],
            'seo_title' => 'ABC Unisex Salon, Pune',
        ])->assertSessionHasNoErrors();

        $this->put($this->appUrl('/website/details'), [
            'business_name' => 'A',
            'whatsapp' => 'abc',
            'email' => 'nope',
            'social' => ['instagram' => 'javascript:alert(1)'],
            'seo_title' => str_repeat('t', 71),
        ])->assertSessionHasErrors(['business_name', 'whatsapp', 'email', 'social.instagram', 'seo_title']);

        $this->get($this->siteUrl('abc-salon.autowave.test'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('template.code', 'minimal')
                ->where('business.primary_color', '#db2777')
                ->where('business.name', 'ABC Unisex Salon')
                ->where('contact.opening_hours', 'Daily 10–9')
                ->where('social', [['key' => 'instagram', 'label' => 'Instagram', 'url' => 'https://instagram.com/abc']])
                ->where('seo.title', 'ABC Unisex Salon, Pune'));

        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'website.design_updated']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'website.details_updated']);
    }

    public function test_online_booking_settings_are_saved(): void
    {
        $tenant = $this->createTenant();
        $this->actingAs($this->ownerOf($tenant));
        $valid = ['enabled' => true, 'auto_confirm' => true, 'min_notice_minutes' => 120, 'max_days_ahead' => 14, 'allow_any_resource' => false];

        $this->put($this->appUrl('/website/booking'), $valid)->assertSessionHasNoErrors();
        $this->put($this->appUrl('/website/booking'), [...$valid, 'min_notice_minutes' => 7, 'max_days_ahead' => 365])
            ->assertSessionHasErrors(['min_notice_minutes', 'max_days_ahead']);

        $this->assertSame($valid, $this->inTenant($tenant, fn () => app(BookingSettings::class)->online()));
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'booking.online_settings_updated']);

        // Businesses without the booking engine have no online booking.
        $cafe = $this->createTenant('Chai Point', 'cafe');
        $this->actingAs($this->ownerOf($cafe));
        $this->put($this->appUrl('/website/booking'), $valid)->assertNotFound();
        $this->get($this->appUrl('/website'))->assertInertia(fn (Assert $page) => $page->where('onlineBooking', null));
    }

    public function test_permissions_decide_who_can_view_and_edit_the_website(): void
    {
        $tenant = $this->createTenant();
        $hero = $this->sectionId($tenant, 'hero');

        $receptionist = User::factory()->create();
        $this->addMember($tenant, $receptionist, 'receptionist');
        $this->actingAs($receptionist);
        $this->get($this->appUrl('/website'))->assertForbidden();
        $this->put($this->appUrl("/website/sections/{$hero}"), ['config' => ['headline' => 'Hacked']])->assertForbidden();
        $this->put($this->appUrl('/website/publish'), ['published' => false])->assertForbidden();

        $manager = User::factory()->create();
        $this->addMember($tenant, $manager, 'manager');
        $this->actingAs($manager);
        $this->get($this->appUrl('/website'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', true));
        $this->put($this->appUrl("/website/sections/{$hero}"), ['config' => ['headline' => 'Managed']])->assertSessionHasNoErrors();

        $this->assertSame('Managed', $this->sectionConfig($tenant, 'hero')['headline']);
    }

    public function test_the_editor_is_hidden_without_the_website_module(): void
    {
        $tenant = $this->createTenant();
        $this->disableModule($tenant, 'website');
        $this->actingAs($this->ownerOf($tenant));

        $this->get($this->appUrl('/website'))->assertNotFound();
        $this->put($this->appUrl('/website/publish'), ['published' => true])->assertNotFound();
    }

    public function test_one_business_cannot_touch_another_business_website(): void
    {
        $salon = $this->createTenant();
        $turf = $this->createTenant('Green Turf', 'turf');
        $turfHero = $this->sectionId($turf, 'hero');
        $before = $this->sectionConfig($turf, 'hero');

        $this->actingAs($this->ownerOf($salon));
        $this->get($this->appUrl("/website/sections/{$turfHero}/edit"))->assertNotFound();
        $this->put($this->appUrl("/website/sections/{$turfHero}"), ['config' => ['headline' => 'Taken over']])->assertNotFound();
        $this->patch($this->appUrl("/website/sections/{$turfHero}/toggle"), ['enabled' => false])->assertNotFound();
        $this->delete($this->appUrl("/website/sections/{$turfHero}"))->assertNotFound();
        $this->put($this->appUrl('/website/sections/order'), ['ids' => [$turfHero]])->assertSessionHasErrors('ids');
        $this->put($this->appUrl('/website/publish'), ['published' => false])->assertSessionHasNoErrors();

        $this->assertSame($before, $this->sectionConfig($turf, 'hero'));
        $this->assertTrue($this->inTenant($turf, fn () => WebsiteConfig::query()->sole()->isPublished()));
        $this->assertFalse($this->inTenant($salon, fn () => WebsiteConfig::query()->sole()->isPublished()));
    }
}
