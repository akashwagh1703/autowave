<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteTemplate;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use App\Support\Phone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Website-wide settings of the current tenant:
 * - design: template and brand colour (website_configs);
 * - details: display name and tagline (`branding` setting), contact details, opening hours and
 *   social links (`business_profile` setting) and SEO (website_configs.seo). The business profile
 *   is shared with the rest of the app (e.g. automation message variables);
 * - publishing.
 */
class UpdateWebsiteSettings
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function design(array $input): WebsiteConfig
    {
        $data = Validator::make($input, [
            'template' => ['required', 'string', Rule::exists('website_templates', 'code')->where('status', 'active')],
            'primary_color' => ['required', 'string', 'regex:'.StoreBusinessRequest::COLOR_PATTERN],
        ], [
            'template.exists' => __('Choose one of the available templates.'),
            'primary_color.regex' => __('Use a hex colour such as #4f46e5.'),
        ])->validate();

        $config = $this->config();
        $template = WebsiteTemplate::query()->where('code', $data['template'])->sole();
        $previous = $config->template?->code;

        $config->update([
            'website_template_id' => $template->id,
            'theme' => [...($config->theme ?? []), 'primary_color' => strtolower($data['primary_color'])],
        ]);

        $this->audit->log('website.design_updated', $config, ['template' => $template->code, 'previous_template' => $previous, 'primary_color' => strtolower($data['primary_color'])]);

        return $config->setRelation('template', $template);
    }

    /** @param  array<string, mixed>  $input */
    public function details(array $input): void
    {
        $social = array_keys(config('website.social'));

        $data = Validator::make($input, [
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'whatsapp' => ['nullable', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'opening_hours' => ['nullable', 'string', 'max:500'],
            'social' => ['nullable', 'array'],
            ...array_combine(array_map(fn (string $key) => "social.{$key}", $social), array_fill(0, count($social), ['nullable', 'string', 'max:255', 'url:http,https'])),
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:160'],
        ], [
            'phone.regex' => __('Enter a valid phone number.'),
            'whatsapp.regex' => __('Enter a valid WhatsApp number, including the country code if it is not :code.', ['code' => '+'.config('crm.default_country_code')]),
            'social.*.url' => __('Enter a full link starting with https://'),
        ])->validate();

        $data = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data)
            + array_fill_keys(['tagline', 'description', 'phone', 'whatsapp', 'email', 'address', 'city', 'opening_hours', 'seo_title', 'seo_description'], null)
            + ['social' => []];

        if ($data['whatsapp'] !== null && Phone::normalize($data['whatsapp']) === null) {
            throw ValidationException::withMessages(['whatsapp' => __('Enter a valid WhatsApp number.')]);
        }

        $branding = $this->context->setting('branding', []);
        $profile = $this->context->setting('business_profile', []);

        $this->write('branding', [...$branding, 'business_name' => $data['business_name'], 'tagline' => $data['tagline']]);
        $this->write('business_profile', [
            ...$profile,
            'description' => $data['description'],
            'phone' => $data['phone'],
            'whatsapp' => $data['whatsapp'],
            'email' => $data['email'],
            'address' => $data['address'],
            'city' => $data['city'],
            'opening_hours' => $data['opening_hours'],
            'social' => array_filter(array_map(fn (string $key) => is_string($data['social'][$key] ?? null) && trim($data['social'][$key]) !== '' ? trim($data['social'][$key]) : null, array_combine($social, $social))),
        ]);

        $config = $this->config();
        $config->update(['seo' => [...($config->seo ?? []), 'title' => $data['seo_title'], 'description' => $data['seo_description']]]);

        $this->audit->log('website.details_updated', $config);
    }

    public function publish(bool $published): WebsiteConfig
    {
        $config = $this->config();
        $config->update([
            'status' => $published ? 'published' : 'draft',
            'published_at' => $published ? ($config->published_at ?? now()) : $config->published_at,
        ]);

        $this->audit->log($published ? 'website.published' : 'website.unpublished', $config);

        return $config;
    }

    private function config(): WebsiteConfig
    {
        return WebsiteConfig::query()->with('template')->firstOrFail();
    }

    /** @param  array<string, mixed>  $value */
    private function write(string $key, array $value): void
    {
        TenantSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
