<?php

namespace App\Domain\AI\Support;

use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Commerce\Models\Product;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * The facts AI may rely on, read from the business's own records (master prompt §41: the database is
 * the source of truth). Public business information only: no customer data, no staff contact details.
 */
class BusinessFacts
{
    private const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AISettings $settings,
    ) {}

    public function name(): string
    {
        $branding = $this->context->setting('branding', []);

        return (string) (is_array($branding) && filled($branding['business_name'] ?? null) ? $branding['business_name'] : $this->context->tenant()->name);
    }

    public function type(): string
    {
        return (string) ($this->context->tenant()->loadMissing('businessType:id,name')->businessType?->name ?? 'Local business');
    }

    /** The facts as plain text lines for a prompt. */
    public function text(): string
    {
        $tenant = $this->context->tenant();
        $branding = $this->context->setting('branding', []);
        $profile = $this->context->setting('business_profile', []);
        $branding = is_array($branding) ? $branding : [];
        $profile = is_array($profile) ? $profile : [];

        $lines = array_filter([
            'Name: '.$this->name(),
            'Type: '.$this->type(),
            filled($branding['tagline'] ?? null) ? 'Tagline: '.$branding['tagline'] : null,
            filled($profile['description'] ?? null) ? 'About: '.Str::limit((string) $profile['description'], 600) : null,
            filled($profile['address'] ?? null) || filled($profile['city'] ?? null)
                ? 'Address: '.implode(', ', array_filter([$profile['address'] ?? null, $profile['city'] ?? null]))
                : null,
            filled($profile['phone'] ?? null) ? 'Phone: '.$profile['phone'] : null,
            'Currency: '.$tenant->currency,
            $this->hours(),
        ]);

        $services = $this->services();
        $products = $this->products();
        $notes = $this->settings->all()['notes'];

        return implode("\n", [
            ...$lines,
            ...($services !== [] ? ['Services (price, duration):', ...$services] : []),
            ...($products !== [] ? ['Products (price):', ...$products] : []),
            ...(filled($notes) ? ['Notes from the owner:', Str::limit((string) $notes, (int) config('ai.notes_max'))] : []),
        ]);
    }

    /** Service and product names, for matching a lead's interest. */
    public function catalogue(): string
    {
        $names = [];

        if ($this->context->hasEngine('service')) {
            $names = Service::query()->active()->ordered()->limit((int) config('ai.context.services'))->pluck('name')->all();
        }

        if ($this->context->hasEngine('commerce')) {
            $names = [...$names, ...Product::query()->active()->ordered()->limit((int) config('ai.context.products'))->pluck('name')->all()];
        }

        return $names !== [] ? implode(', ', $names) : 'not listed';
    }

    private function hours(): ?string
    {
        if (! $this->context->hasEngine('booking')) {
            return null;
        }

        $days = [];

        foreach (app(BookingSettings::class)->defaultHours() as $hours) {
            $day = self::WEEKDAYS[(int) ($hours['weekday'] ?? 0)] ?? null;

            if ($day) {
                $days[] = "{$day} {$hours['starts_at']}–{$hours['ends_at']}";
            }
        }

        $closed = array_values(array_diff(self::WEEKDAYS, array_map(fn (string $line) => substr($line, 0, 3), $days)));

        return 'Usual opening hours: '.($days !== [] ? implode(', ', $days) : 'not set').($closed !== [] ? '; closed '.implode(', ', $closed) : '')
            .' (bookings are confirmed by the team; staff availability varies)';
    }

    /** @return list<string> */
    private function services(): array
    {
        if (! $this->context->hasEngine('service')) {
            return [];
        }

        return Service::query()->active()->ordered()->with('category:id,name')->limit((int) config('ai.context.services'))->get()
            ->map(fn (Service $service) => '- '.$service->name
                .($service->category ? " ({$service->category->name})" : '')
                .': '.($service->price !== null ? $this->money($service->price) : 'price on request')
                .($service->duration_minutes ? ", {$service->duration_minutes} min" : ''))
            ->all();
    }

    /** @return list<string> */
    private function products(): array
    {
        if (! $this->context->hasEngine('commerce')) {
            return [];
        }

        return Product::query()->active()->ordered()->limit((int) config('ai.context.products'))->get()
            ->map(fn (Product $product) => '- '.$product->name.': '.$this->money($product->price)
                .($product->track_stock && $product->stock_quantity <= 0 ? ' (out of stock)' : ''))
            ->all();
    }

    private function money(mixed $amount): string
    {
        $value = (float) $amount;

        return $this->context->tenant()->currency.' '.(floor($value) === $value ? number_format($value) : number_format($value, 2));
    }
}
