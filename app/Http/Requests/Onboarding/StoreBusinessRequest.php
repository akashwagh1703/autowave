<?php

namespace App\Http\Requests\Onboarding;

use App\Domain\Onboarding\Support\OnboardingCatalog;
use App\Domain\Tenant\Support\TenantSlug;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreBusinessRequest extends FormRequest
{
    public const PHONE_PATTERN = '/^\+?[0-9][0-9\-\s]{6,19}$/';

    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::squish((string) $this->input('name')),
            'slug' => Str::lower(trim((string) $this->input('slug'))),
            'modules' => array_values(array_unique(array_filter((array) $this->input('modules', []), 'is_string'))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $catalog = app(OnboardingCatalog::class);

        return [
            'business_type' => ['required', 'string', Rule::in($catalog->businessTypes()->keys()->all())],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'max:63', function (string $attribute, mixed $value, Closure $fail) {
                if (! TenantSlug::isValid($value)) {
                    $fail(__('Use 3–63 lowercase letters, numbers or hyphens (not a reserved word).'));
                } elseif (! TenantSlug::isAvailable($value)) {
                    $fail(__('This web address is already taken.'));
                }
            }],
            'phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'city' => ['required', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'modules' => ['array'],
            'modules.*' => ['string', Rule::in($catalog->moduleCodes())],
            'primary_color' => ['nullable', 'string', 'regex:'.self::COLOR_PATTERN],
            'tagline' => ['nullable', 'string', 'max:120'],
            'website_template' => ['required', 'string', Rule::in($catalog->templateCodes())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_type.in' => __('Choose one of the listed business types.'),
            'phone.regex' => __('Enter a valid phone number.'),
            'primary_color.regex' => __('Use a hex colour such as #4f46e5.'),
            'modules.*.in' => __('One of the selected capabilities is not available.'),
        ];
    }
}
