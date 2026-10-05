<?php

namespace Tests\Feature\Onboarding;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Engine\Services\EngineManager;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Onboarding\Notifications\BusinessReady;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Http\Middleware\ResolveTenantFromMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'business_type' => 'beauty_salon',
            'name' => 'Glow Studio',
            'slug' => 'glow-studio',
            'phone' => '+91 98765 43210',
            'email' => 'hello@glow.test',
            'city' => 'Pune',
            'address' => '12 MG Road',
            'description' => 'Hair and skin care in Koregaon Park.',
            'modules' => ['crm', 'leads', 'customers', 'messaging', 'website'],
            'primary_color' => '#db2777',
            'tagline' => 'Look good, feel great',
            'website_template' => 'modern',
            ...$overrides,
        ];
    }

    public function test_guests_and_unverified_users_cannot_onboard(): void
    {
        $this->get($this->appUrl('/onboarding'))->assertRedirect(route('login'));
        $this->post($this->appUrl('/onboarding'), $this->payload())->assertRedirect(route('login'));

        $this->actingAs(User::factory()->unverified()->create())
            ->get($this->appUrl('/onboarding'))
            ->assertRedirect(route('verification.notice'));

        $this->assertSame(0, Tenant::query()->where('slug', 'glow-studio')->count());
    }

    public function test_the_wizard_offers_public_business_types_modules_and_templates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get($this->appUrl('/onboarding'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('onboarding/Create')
                ->where('limitReached', false)
                ->where('hasWorkspaces', false)
                ->where('defaults.email', $user->email)
                ->has('catalog.business_types', 6)
                ->where('catalog.business_types.0.code', 'beauty_salon')
                ->where('catalog.business_types.0.icon', 'spa')
                ->where('catalog.business_types.0.templates', ['elegant', 'modern', 'premium'])
                ->where('catalog.business_types.0.required_modules', fn ($modules) => collect($modules)->contains('payments'))
                ->has('catalog.templates', 5)
                ->has('catalog.modules', count(config('catalog.modules')))
                ->where('catalog.domain_suffix', '.autowave.test'));
    }

    public function test_the_internal_business_type_is_never_offered(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->appUrl('/onboarding'))
            ->assertInertia(fn (Assert $page) => $page->where(
                'catalog.business_types',
                fn ($types) => ! collect($types)->pluck('code')->contains('autowave_internal'),
            ));

        $this->actingAs(User::factory()->create())
            ->post($this->appUrl('/onboarding'), $this->payload(['business_type' => 'autowave_internal']))
            ->assertSessionHasErrors('business_type');
    }

    public function test_slug_availability_check(): void
    {
        $this->createTenant('ABC Salon');
        $this->actingAs(User::factory()->create());

        $this->getJson($this->appUrl('/onboarding/slug?slug=glow-studio'))
            ->assertOk()
            ->assertJson(['slug' => 'glow-studio', 'valid' => true, 'available' => true, 'suggestion' => null, 'domain' => 'glow-studio.autowave.test']);

        $this->getJson($this->appUrl('/onboarding/slug?slug=ABC-Salon'))
            ->assertJson(['slug' => 'abc-salon', 'valid' => true, 'available' => false, 'suggestion' => 'abc-salon-2']);

        $this->getJson($this->appUrl('/onboarding/slug?slug=admin'))
            ->assertJson(['valid' => false, 'available' => false, 'domain' => null, 'suggestion' => 'admin-business']);

        $this->getJson($this->appUrl('/onboarding/slug?slug=bad_slug!'))
            ->assertJson(['valid' => false, 'available' => false]);

        $this->getJson($this->appUrl('/onboarding/slug?slug=&name=ABC%20Salon'))
            ->assertJson(['valid' => false, 'suggestion' => 'abc-salon-2']);
    }

    public function test_onboarding_provisions_a_complete_workspace(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post($this->appUrl('/onboarding'), $this->payload());

        $tenant = Tenant::query()->where('slug', 'glow-studio')->sole();
        $response->assertRedirect(route('dashboard'))
            ->assertSessionHas('success')
            ->assertSessionHas(ResolveTenantFromMembership::SESSION_KEY, $tenant->id);

        $this->assertSame('Glow Studio', $tenant->name);
        $this->assertSame($user->id, $tenant->created_by_user_id);
        $this->assertSame('beauty_salon', $tenant->businessType->code);
        $this->assertSame('glow-studio.autowave.test', $tenant->primaryDomain->domain);

        $context = app(TenantContext::class);
        $context->run($tenant, function () use ($tenant, $user, $context) {
            $this->assertSame(['owner'], $tenant->memberships()->where('user_id', $user->id)->sole()->roles()->pluck('slug')->all());

            $this->assertEquals(
                ['business_name' => 'Glow Studio', 'primary_color' => '#db2777', 'tagline' => 'Look good, feel great', 'logo_path' => null],
                $context->setting('branding'),
            );
            $this->assertEquals(
                ['phone' => '+91 98765 43210', 'email' => 'hello@glow.test', 'city' => 'Pune', 'address' => '12 MG Road', 'description' => 'Hair and skin care in Koregaon Park.'],
                $context->setting('business_profile'),
            );
            $this->assertSame(config('catalog.business_types.beauty_salon.configuration.dashboard_widgets'), $context->setting('dashboard_widgets'));
            $this->assertNull($context->setting('website_sections'));

            $config = WebsiteConfig::query()->with('template')->sole();
            $this->assertSame('modern', $config->template->code);
            $this->assertSame('#db2777', $config->theme['primary_color']);
            $this->assertSame('Glow Studio', $config->seo['title']);
            $this->assertTrue($config->isPublished());

            $this->assertSame(
                config('catalog.business_types.beauty_salon.configuration.website_sections'),
                WebsiteSection::query()->orderBy('sort_order')->pluck('type')->all(),
            );
            $hero = WebsiteSection::query()->where('type', 'hero')->sole();
            $this->assertEquals(['headline' => 'Glow Studio', 'subheadline' => 'Look good, feel great', 'cta' => 'book'], $hero->configuration);
        });

        // Selected modules + engine-required (payments) + dependencies; unticked presets stay off.
        $this->assertEqualsCanonicalizing(
            ['customers', 'crm', 'leads', 'messaging', 'website', 'payments'],
            app(ModuleManager::class)->enabledCodes($tenant),
        );
        $this->assertEqualsCanonicalizing(['service', 'booking', 'commerce'], app(EngineManager::class)->enabledCodes($tenant));

        $audit = AuditLog::query()->where('action', 'tenant.created')->sole();
        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('onboarding', $audit->metadata['source']);

        $this->get($this->appUrl('/dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('business/Dashboard')->where('tenant.id', $tenant->id));
    }

    public function test_the_new_website_is_live_on_the_default_subdomain(): void
    {
        $this->actingAs(User::factory()->create())->post($this->appUrl('/onboarding'), $this->payload());

        $this->get($this->siteUrl('glow-studio.autowave.test'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('website/Home')
                ->where('business.name', 'Glow Studio')
                ->where('business.tagline', 'Look good, feel great')
                ->where('business.primary_color', '#db2777')
                ->where('template.code', 'modern')
                ->where('template.hero', 'gradient')
                ->where('contact.phone', '+91 98765 43210')
                ->where('sections.1.type', 'hero'));
    }

    public function test_the_owner_gets_a_welcome_email_with_the_website_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post($this->appUrl('/onboarding'), $this->payload())->assertRedirect(route('dashboard'));

        Notification::assertSentTo($user, BusinessReady::class, function (BusinessReady $notification) use ($user) {
            $mail = $notification->toMail($user);

            return $mail->subject === 'Glow Studio is ready on AutoWave'
                && str_ends_with((string) $notification->websiteUrl, '://glow-studio.autowave.test')
                && $notification->trialDays === (int) config('billing.trial.days')
                && $mail->actionUrl === rtrim(config('app.url'), '/').'/dashboard';
        });

        $this->post($this->appUrl('/onboarding'), $this->payload(['name' => 'Glow Two', 'slug' => 'glow-two', 'modules' => ['crm']]));
        Notification::assertSentTo($user, BusinessReady::class, fn (BusinessReady $notification) => $notification->businessName === 'Glow Two' && $notification->websiteUrl === null);
    }

    public function test_leaving_out_the_website_module_keeps_the_site_offline(): void
    {
        $this->actingAs(User::factory()->create())
            ->post($this->appUrl('/onboarding'), $this->payload(['modules' => ['crm']]))
            ->assertRedirect(route('dashboard'));

        $tenant = Tenant::query()->where('slug', 'glow-studio')->sole();
        $this->assertEqualsCanonicalizing(['customers', 'crm', 'payments'], app(ModuleManager::class)->enabledCodes($tenant));

        // The configuration exists so enabling the module later needs no setup.
        $this->assertTrue(app(TenantContext::class)->run($tenant, fn () => WebsiteConfig::query()->exists()));
        $this->get($this->siteUrl('glow-studio.autowave.test'))->assertNotFound();
    }

    public function test_input_is_validated(): void
    {
        $this->actingAs(User::factory()->create())
            ->post($this->appUrl('/onboarding'), [])
            ->assertSessionHasErrors(['business_type', 'name', 'slug', 'phone', 'city', 'website_template']);

        $this->post($this->appUrl('/onboarding'), $this->payload([
            'phone' => 'call me',
            'email' => 'not-an-email',
            'primary_color' => 'pink',
            'tagline' => str_repeat('x', 121),
            'description' => str_repeat('x', 501),
            'modules' => ['crm', 'teleportation'],
            'website_template' => 'neon',
        ]))->assertSessionHasErrors(['phone', 'email', 'primary_color', 'tagline', 'description', 'modules.1', 'website_template']);

        $this->assertSame(0, Tenant::query()->where('slug', 'glow-studio')->count());
    }

    public function test_slugs_must_be_valid_and_available(): void
    {
        $this->createTenant('ABC Salon');
        $this->actingAs(User::factory()->create());

        $this->post($this->appUrl('/onboarding'), $this->payload(['slug' => 'abc-salon']))->assertSessionHasErrors('slug');
        $this->post($this->appUrl('/onboarding'), $this->payload(['slug' => 'www']))->assertSessionHasErrors('slug');
        $this->post($this->appUrl('/onboarding'), $this->payload(['slug' => '-x-']))->assertSessionHasErrors('slug');

        $this->post($this->appUrl('/onboarding'), $this->payload(['slug' => '  Glow-Studio ']))->assertRedirect(route('dashboard'));
        $this->assertTrue(Tenant::query()->where('slug', 'glow-studio')->exists());
    }

    public function test_a_user_can_only_create_a_limited_number_of_businesses(): void
    {
        config(['autowave.onboarding.max_businesses_per_user' => 1]);
        $user = User::factory()->create();

        $this->actingAs($user)->post($this->appUrl('/onboarding'), $this->payload())->assertRedirect(route('dashboard'));

        $this->post($this->appUrl('/onboarding'), $this->payload(['name' => 'Glow Two', 'slug' => 'glow-two']))
            ->assertSessionHasErrors('business');
        $this->assertFalse(Tenant::query()->where('slug', 'glow-two')->exists());

        $this->get($this->appUrl('/onboarding'))->assertInertia(fn (Assert $page) => $page->where('limitReached', true));
        $this->get($this->appUrl('/workspaces'))->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
    }

    public function test_an_existing_member_can_add_another_business_and_is_switched_to_it(): void
    {
        $salon = $this->createTenant('ABC Salon');
        $owner = $this->ownerOf($salon);

        $this->actingAs($owner)
            ->get($this->appUrl('/onboarding'))
            ->assertInertia(fn (Assert $page) => $page->where('hasWorkspaces', true));

        $this->post($this->appUrl('/onboarding'), $this->payload(['business_type' => 'turf', 'name' => 'ABC Turf', 'slug' => 'abc-turf', 'website_template' => 'corporate']))
            ->assertRedirect(route('dashboard'));

        $turf = Tenant::query()->where('slug', 'abc-turf')->sole();
        $this->get($this->appUrl('/dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('tenant.id', $turf->id));
        $this->assertCount(2, $owner->accessibleTenants()->get());
    }

    public function test_users_whose_access_was_removed_are_not_sent_to_onboarding(): void
    {
        $tenant = $this->createTenant();
        $member = User::factory()->create();
        $this->addMember($tenant, $member, 'staff', MembershipStatus::Suspended);

        $this->actingAs($member)->get($this->appUrl('/dashboard'))->assertRedirect(route('workspaces.index'));
    }
}
