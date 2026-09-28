<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Create and edit a product. The category is checked against the current tenant and names/SKUs
 * for uniqueness by SaveProduct; the image by ManageMedia. Permissions are enforced by route
 * middleware.
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['name', 'sku'] as $field) {
            if ($this->has($field)) {
                $merge[$field] = Str::squish((string) $this->input($field));
            }
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = config('commerce.limits');

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'product_category_id' => ['nullable', 'integer'],
            'sku' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._\/ -]+$/'],
            'price' => ['required', 'numeric', 'min:0', 'max:'.$limits['max_price']],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:'.$limits['max_price']],
            'is_active' => ['required', 'boolean'],
            'food_type' => ['nullable', Rule::in(array_keys(config('food.food_types')))],
            'is_available' => ['nullable', 'boolean'],
            'track_stock' => ['required', 'boolean'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:'.$limits['max_stock']],
            'opening_stock' => ['nullable', 'integer', 'min:0', 'max:'.$limits['max_stock']],
            'image' => ['nullable', 'file'],
        ];
    }

    public function messages(): array
    {
        return ['sku.regex' => __('Use letters, numbers, spaces, dots, dashes or slashes.')];
    }

    /** Validated data ready for SaveProduct. */
    public function productData(): array
    {
        $data = $this->safe()->except(['image']);
        $data['is_active'] = (bool) $data['is_active'];
        $data['track_stock'] = (bool) $data['track_stock'];

        if (array_key_exists('is_available', $data)) {
            $data['is_available'] = $data['is_available'] === null ? true : (bool) $data['is_available'];
        }

        $data['product_category_id'] = isset($data['product_category_id']) ? (int) $data['product_category_id'] : null;

        return $data;
    }
}
