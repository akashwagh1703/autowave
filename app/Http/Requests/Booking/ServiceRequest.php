<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Create and edit a service or package. Category, resource and item ids are checked against the
 * current tenant by SaveService. Permissions are enforced by route middleware.
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

        foreach (['is_active', 'is_package'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false]);
            }
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
            'is_package' => ['sometimes', 'boolean'],
            'resource_ids' => ['nullable', 'array', 'max:200'],
            'resource_ids.*' => ['integer', 'distinct'],
            'included_service_ids' => ['nullable', 'array', 'max:50'],
            'included_service_ids.*' => ['integer', 'distinct'],
            'product_ids' => ['nullable', 'array', 'max:50'],
            'product_ids.*' => ['integer', 'distinct'],
        ];
    }

    /** Validated data ready for SaveService. */
    public function serviceData(): array
    {
        $data = $this->validated();
        $data['is_active'] = (bool) $data['is_active'];
        $data['is_package'] = (bool) ($data['is_package'] ?? false);
        $data['service_category_id'] = isset($data['service_category_id']) ? (int) $data['service_category_id'] : null;
        $data['included_service_ids'] = array_values($data['included_service_ids'] ?? []);
        $data['product_ids'] = array_values($data['product_ids'] ?? []);

        return $data;
    }
}
