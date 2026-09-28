<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Create and edit a service. Category and resource ids are checked against the current tenant by
 * SaveService. Permissions are enforced by route middleware.
 */
class ServiceRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:2000'],
            'service_category_id' => ['nullable', 'integer'],
            'duration_minutes' => ['required', 'integer', 'min:'.config('booking.duration.min'), 'max:'.config('booking.duration.max')],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'is_active' => ['required', 'boolean'],
            'resource_ids' => ['nullable', 'array', 'max:200'],
            'resource_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** Validated data ready for SaveService. */
    public function serviceData(): array
    {
        $data = $this->validated();
        $data['is_active'] = (bool) $data['is_active'];
        $data['service_category_id'] = isset($data['service_category_id']) ? (int) $data['service_category_id'] : null;

        return $data;
    }
}
