<?php

namespace App\Domain\Website\Services;

use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Services\Coupons;
use App\Domain\Commerce\Services\OrderPricing;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteSection;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use App\Support\Phone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Ordering from the public website, on top of the commerce engine (ADR-017).
 *
 * - Open when the tenant has the commerce engine, the products section is on, online ordering
 *   is on with pickup and/or delivery, and at least one active product exists.
 * - The cart lives in the visitor's browser; the server re-prices it on every quote and again
 *   when the order is placed (PlaceOrder), so a tampered cart cannot change a price.
 * - Website orders start pending unless online auto-confirm is on.
 */
class OnlineShop
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CommerceSettings $settings,
        private readonly OrderPricing $pricing,
        private readonly PlaceOrder $placeOrder,
        private readonly Coupons $coupons,
    ) {}

    public function isOpen(): bool
    {
        return $this->context->hasEngine('commerce')
            && $this->settings->online()['enabled']
            && $this->settings->onlineFulfilment() !== []
            && WebsiteSection::query()->where('type', 'products')->where('enabled', true)->exists()
            && Product::query()->active()->exists();
    }

    /**
     * What the visitor sees before checking out: server prices, stock problems and fees.
     *
     * A coupon code that does not apply is reported in `coupon_error` and ignored (it never blocks checkout).
     *
     * @return array{lines: list<array<string, mixed>>, subtotal: string, discount: string, coupon: ?array{code: string, summary: string}, coupon_error: ?string, delivery_fee: string, total: string, min_order: ?string, issues: list<string>, can_checkout: bool}
     */
    public function quote(mixed $items, ?string $fulfilment, ?string $couponCode = null): array
    {
        $priced = $this->pricing->lines(OrderPricing::normalize($items));
        $online = $this->settings->online();
        $deliveryFee = $fulfilment === 'delivery' && in_array('delivery', $this->settings->onlineFulfilment(), true)
            ? $this->settings->deliveryFeeFor($priced['subtotal'])
            : '0.00';
        $issues = $priced['issues'];

        if ($online['min_order'] !== null && bccomp($priced['subtotal'], $online['min_order'], 2) < 0) {
            $issues[] = __('The minimum order is :amount.', ['amount' => $online['min_order']]);
        }

        $coupon = null;
        $couponError = null;

        try {
            $coupon = $this->coupons->resolve($couponCode, $priced['subtotal'], online: true);
        } catch (ValidationException $exception) {
            $couponError = collect($exception->errors())->flatten()->first();
        }

        $discount = $coupon['discount'] ?? '0.00';

        return [
            'lines' => array_map(fn (array $line) => [
                'product_id' => $line['product_id'],
                'name' => $line['name'],
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'line_total' => $line['line_total'],
                'available' => $line['available'],
                'issue' => $line['issue'],
            ], $priced['lines']),
            'subtotal' => $priced['subtotal'],
            'discount' => $discount,
            'coupon' => $coupon ? ['code' => $coupon['coupon']->code, 'summary' => $coupon['coupon']->summary()] : null,
            'coupon_error' => $couponError,
            'delivery_fee' => $deliveryFee,
            'total' => bcadd(bcsub($priced['subtotal'], $discount, 2), $deliveryFee, 2),
            'min_order' => $online['min_order'],
            'issues' => $issues,
            'can_checkout' => $issues === [],
        ];
    }

    /** @param  array<string, mixed>  $input */
    public function place(array $input): Order
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'fulfilment' => ['required', 'string'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'coupon_code' => ['nullable', 'string', 'max:30'],
            'items' => ['required', 'array'],
        ], [
            'phone.regex' => __('Enter a valid phone number.'),
            'fulfilment.required' => __('Choose pickup or delivery.'),
            'items.required' => __('Your cart is empty.'),
        ])->validate();

        if (Phone::normalize($data['phone']) === null) {
            throw ValidationException::withMessages(['phone' => __('Enter a valid phone number.')]);
        }

        return $this->placeOrder->handle([
            'customer' => ['name' => trim($data['name']), 'phone' => trim($data['phone']), 'email' => filled($data['email'] ?? null) ? trim($data['email']) : null],
            'items' => $data['items'],
            'fulfilment' => $data['fulfilment'],
            'delivery_address' => $data['delivery_address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'coupon_code' => $data['coupon_code'] ?? null,
            'source' => 'website',
        ]);
    }

    /**
     * Checkout settings for the website (only public values).
     *
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $online = $this->settings->online();

        return [
            'fulfilment' => $this->settings->onlineFulfilment(),
            'delivery_fee' => $online['delivery_fee'],
            'free_delivery_over' => $online['free_delivery_over'],
            'min_order' => $online['min_order'],
            'delivery_note' => $online['delivery_note'],
            'max_quantity' => (int) config('commerce.limits.max_quantity'),
            'max_items' => (int) config('commerce.limits.items_per_order'),
            'coupons' => $this->coupons->enabled() && Coupon::query()->where('is_active', true)->where('online', true)->exists(),
        ];
    }
}
