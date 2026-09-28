<?php

namespace App\Http\Requests\Commerce;

use App\Http\Requests\Onboarding\StoreBusinessRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An order placed by the team. Prices come from the products (PlaceOrder); only the discount and
 * the delivery fee can be set by hand. Permissions are enforced by route middleware.
 */
class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = config('commerce.limits');

        return [
            'customer_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'array'],
            // A dine-in order can be a walk-in with no customer.
            'customer.name' => [
                'nullable',
                Rule::requiredIf(fn () => blank($this->input('customer_id')) && $this->input('fulfilment') !== 'dine_in'),
                'string', 'min:2', 'max:120',
            ],
            'dining_table_id' => ['nullable', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:30'],
            'customer.phone' => ['nullable', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'customer.email' => ['nullable', 'string', 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:'.$limits['items_per_order']],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.$limits['max_quantity']],
            'fulfilment' => ['required', 'string', Rule::in(array_keys(config('commerce.fulfilment')))],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:'.$limits['max_price']],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:'.$limits['max_price']],
            'notes' => ['nullable', 'string', 'max:2000'],
            'completed' => ['nullable', 'boolean'],
            'payment' => ['nullable', 'array'],
            'payment.amount' => ['nullable', 'numeric', 'min:0', 'max:'.$limits['max_price']],
            'payment.method' => ['nullable', 'required_with:payment.amount', Rule::in(array_keys(config('commerce.payment_methods')))],
            'payment.reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer.name.required' => __('Choose a customer or enter their name.'),
            'customer.phone.regex' => __('Enter a valid phone number.'),
            'items.required' => __('Add at least one product.'),
            'items.min' => __('Add at least one product.'),
            'fulfilment.required' => __('Choose how the order reaches the customer.'),
            'payment.method.required_with' => __('Choose how the customer paid.'),
        ];
    }

    /** Validated data ready for PlaceOrder. */
    public function orderData(): array
    {
        $data = $this->validated();
        $data['source'] = 'manual';
        $data['completed'] = (bool) ($data['completed'] ?? false);
        $data['customer_id'] = isset($data['customer_id']) ? (int) $data['customer_id'] : null;

        if (empty($data['payment']['amount']) || (float) $data['payment']['amount'] <= 0) {
            unset($data['payment']);
        }

        return $data;
    }
}
