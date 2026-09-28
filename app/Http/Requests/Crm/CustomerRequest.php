<?php

namespace App\Http\Requests\Crm;

use App\Domain\Customer\Support\CustomerTags;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Create and edit customers. Permissions are enforced by route middleware.
 */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::squish((string) $this->input('name')),
            'tags' => array_values(array_filter((array) $this->input('tags', []), 'is_string')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['nullable', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:255'],
            'tags' => ['array', 'max:'.CustomerTags::MAX_TAGS],
            'tags.*' => ['string', 'max:'.CustomerTags::MAX_LENGTH],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter a valid phone number.'),
            'tags.max' => __('Use at most :max tags.'),
        ];
    }
}
