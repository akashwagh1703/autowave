<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Media\Models\Media;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Actions\UpdateWebsiteSettings;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Models\WebsiteTemplate;
use App\Domain\Website\Services\OnlineBooking;
use App\Domain\Website\Services\WebsiteContent;
use App\Domain\Website\Support\SectionCatalog;
use App\Domain\Website\Support\WebsitePreview;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WebsitePresenter;
use App\Support\Enums\CatalogStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Website editor: overview, design, business details, publishing and online booking. */
class WebsiteController extends Controller
{
    public function index(Request $request, TenantContext $context, SectionCatalog $catalog, WebsiteContent $content, BookingSettings $bookingSettings, OnlineBooking $booking): Response
    {
        $config = $this->config();
        $tenant = $context->tenant();
        $page = $content->page($config);
        $live = array_column($page['sections'], 'id');
        $sections = SectionCatalog::sorted(WebsiteSection::query()->get());
        $present = array_map(fn (WebsiteSection $section) => $section->type, $sections);
        $profile = $context->setting('business_profile', []);
        $siteUrl = WebsitePresenter::siteUrl($tenant);

        return Inertia::render('business/website/Index', [
            'website' => [
                'status' => $config->status,
                'published_at' => $config->published_at?->toIso8601String(),
                'template' => $config->template->name,
                'url' => $siteUrl,
                'preview_url' => $siteUrl && ! $config->isPublished() ? WebsitePreview::url($tenant, $siteUrl) : null,
            ],
            'sections' => array_map(fn (WebsiteSection $section) => WebsitePresenter::section($section, $catalog, in_array($section->id, $live, true)), $sections),
            'addable' => collect(SectionCatalog::all())
                ->filter(fn (array $definition, string $type) => ! in_array($type, $present, true) && $catalog->isAvailable($type))
                ->map(fn (array $definition, string $type) => ['type' => $type, 'label' => $definition['label'], 'description' => $definition['description'] ?? null])
                ->values()
                ->all(),
            'checklist' => array_values(array_filter([
                ['key' => 'contact', 'label' => __('Add your phone or WhatsApp number'), 'done' => filled($profile['phone'] ?? null) || filled($profile['whatsapp'] ?? null), 'href' => '/website/details'],
                ['key' => 'description', 'label' => __('Describe your business'), 'done' => filled($profile['description'] ?? null), 'href' => '/website/details'],
                ['key' => 'logo', 'label' => __('Upload your logo'), 'done' => Media::query()->where('collection', 'logo')->exists(), 'href' => '/website/design'],
                $context->hasEngine('service')
                    ? ['key' => 'services', 'label' => __('Add the services you offer'), 'done' => Service::query()->active()->exists(), 'href' => '/services']
                    : null,
                $context->hasEngine('booking')
                    ? ['key' => 'booking', 'label' => __('Open online booking'), 'done' => $page['booking'] !== null, 'href' => '/website#online-booking']
                    : null,
                ['key' => 'published', 'label' => __('Publish your website'), 'done' => $config->isPublished(), 'href' => null],
            ])),
            'onlineBooking' => $context->hasEngine('booking') ? [
                'settings' => $bookingSettings->online(),
                'open' => $booking->isOpen(),
                'noticeOptions' => config('booking.online_notice_options'),
                'daysAheadOptions' => config('booking.online_days_ahead_options'),
                'hasResources' => $booking->resources()->isNotEmpty(),
                'resourceLabel' => $bookingSettings->resourceLabels(),
            ] : null,
            'canManage' => $request->user()->can('website.manage'),
        ]);
    }

    public function publish(Request $request, UpdateWebsiteSettings $settings): RedirectResponse
    {
        $published = (bool) $request->validate(['published' => ['required', 'boolean']])['published'];
        $settings->publish($published);

        return back()->with('success', $published ? __('Your website is live.') : __('Your website is now hidden from visitors.'));
    }

    public function design(Request $request, TenantContext $context): Response
    {
        $config = $this->config();
        $branding = $context->setting('branding', []);

        return Inertia::render('business/website/Design', [
            'design' => [
                'template' => $config->template->code,
                'primary_color' => $config->theme['primary_color'] ?? $branding['primary_color'] ?? '#4f46e5',
            ],
            'businessName' => $branding['business_name'] ?? $context->tenant()->name,
            'templates' => WebsiteTemplate::query()->where('status', CatalogStatus::Active)->orderBy('sort_order')->get()
                ->map(fn (WebsiteTemplate $template) => [
                    'code' => $template->code,
                    'name' => $template->name,
                    'description' => $template->description,
                    'theme' => $template->theme(),
                ])->all(),
            'logo' => ($logo = Media::query()->inCollection('logo')->first()) ? WebsitePresenter::media($logo) : null,
            'logoRules' => WebsitePresenter::mediaRules('logo'),
            'canManage' => $request->user()->can('website.manage'),
        ]);
    }

    public function updateDesign(Request $request, UpdateWebsiteSettings $settings): RedirectResponse
    {
        $settings->design($request->only(['template', 'primary_color']));

        return back()->with('success', __('Design saved.'));
    }

    public function details(Request $request, TenantContext $context): Response
    {
        $config = $this->config();
        $branding = $context->setting('branding', []);
        $profile = $context->setting('business_profile', []);

        return Inertia::render('business/website/Details', [
            'details' => [
                'business_name' => $branding['business_name'] ?? $context->tenant()->name,
                'tagline' => $branding['tagline'] ?? '',
                'description' => $profile['description'] ?? '',
                'phone' => $profile['phone'] ?? '',
                'whatsapp' => $profile['whatsapp'] ?? '',
                'email' => $profile['email'] ?? '',
                'address' => $profile['address'] ?? '',
                'city' => $profile['city'] ?? '',
                'opening_hours' => $profile['opening_hours'] ?? '',
                'social' => collect(config('website.social'))->map(fn ($label, string $key) => $profile['social'][$key] ?? '')->all(),
                'seo_title' => $config->seo['title'] ?? '',
                'seo_description' => $config->seo['description'] ?? '',
            ],
            'socialNetworks' => config('website.social'),
            'canManage' => $request->user()->can('website.manage'),
        ]);
    }

    public function updateDetails(Request $request, UpdateWebsiteSettings $settings): RedirectResponse
    {
        $settings->details($request->only([
            'business_name', 'tagline', 'description', 'phone', 'whatsapp', 'email', 'address', 'city', 'opening_hours', 'social', 'seo_title', 'seo_description',
        ]));

        return back()->with('success', __('Business details saved.'));
    }

    public function updateBooking(Request $request, BookingSettings $bookingSettings, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'auto_confirm' => ['required', 'boolean'],
            'min_notice_minutes' => ['required', 'integer', Rule::in(config('booking.online_notice_options'))],
            'max_days_ahead' => ['required', 'integer', Rule::in(config('booking.online_days_ahead_options'))],
            'allow_any_resource' => ['required', 'boolean'],
        ]);

        $online = [
            'enabled' => (bool) $validated['enabled'],
            'auto_confirm' => (bool) $validated['auto_confirm'],
            'min_notice_minutes' => (int) $validated['min_notice_minutes'],
            'max_days_ahead' => (int) $validated['max_days_ahead'],
            'allow_any_resource' => (bool) $validated['allow_any_resource'],
        ];

        $bookingSettings->update(['online' => $online]);
        $audit->log('booking.online_settings_updated', null, $online);

        return back()->with('success', __('Online booking settings saved.'));
    }

    private function config(): WebsiteConfig
    {
        return WebsiteConfig::query()->with('template')->firstOrFail();
    }
}
