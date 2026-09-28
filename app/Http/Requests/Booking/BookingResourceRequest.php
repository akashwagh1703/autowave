<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\Onboarding\StoreBusinessRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Create and edit a bookable resource. Member and service ids are checked against the current
 * tenant, and working hours normalised, by SaveBookingResource.
 */
class BookingResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => Str::squish((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['required', 'string', 'regex:'.StoreBusinessRequest::COLOR_PATTERN],
            'is_active' => ['required', 'boolean'],
            'tenant_user_id' => ['nullable', 'integer'],
            'service_ids' => ['nullable', 'array', 'max:500'],
            'service_ids.*' => ['integer', 'distinct'],
            'working_hours' => ['present', 'array', 'max:28'],
            'working_hours.*.weekday' => ['required', 'integer', 'between:1,7'],
            'working_hours.*.starts_at' => ['required', 'date_format:H:i'],
            'working_hours.*.ends_at' => ['required', 'date_format:H:i'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:'.config('booking.pricing.max_rate')],
            'rates' => ['nullable', 'array', 'max:'.config('booking.pricing.max_rates')],
            'rates.*' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'color.regex' => __('Use a hex colour such as #4f46e5.'),
            'working_hours.*.starts_at.date_format' => __('Use a time such as 09:30.'),
            'working_hours.*.ends_at.date_format' => __('Use a time such as 18:00.'),
        ];
    }

    /** Validated data ready for SaveBookingResource. */
    public function resourceData(): array
    {
        $data = $this->validated();
        $data['is_active'] = (bool) $data['is_active'];
        $data['tenant_user_id'] = isset($data['tenant_user_id']) ? (int) $data['tenant_user_id'] : null;
        $data['hourly_rate'] = $data['hourly_rate'] ?? null;
        $data['rates'] = $this->input('rates') ?? [];

        return $data;
    }
}
