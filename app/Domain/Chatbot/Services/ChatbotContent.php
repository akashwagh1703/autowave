<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\Billing\Support\Entitlements;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Support\BatchSchedule;
use App\Domain\Media\Models\Media;
use App\Domain\Messaging\Support\Interactive;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Services\OnlineBooking;
use App\Domain\Website\Services\OnlineReservations;
use App\Domain\Website\Services\OnlineShop;
use App\Domain\Website\Support\SectionCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the WhatsApp assistant may tell a contact, read from the business's own records at reply time
 * (master prompt §54: one source of truth). Public information only, the same the website shows: no
 * customer data, no staff contact details, no stock counts.
 */
class ChatbotContent
{
    private const SYMBOLS = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly OnlineBooking $booking,
        private readonly OnlineShop $shop,
        private readonly OnlineReservations $reservations,
        private readonly BookingSettings $bookingSettings,
        private readonly SectionCatalog $catalog,
        private readonly Entitlements $entitlements,
    ) {}

    public function name(): string
    {
        $branding = $this->context->setting('branding', []);

        return (string) (is_array($branding) && filled($branding['business_name'] ?? null) ? $branding['business_name'] : $this->context->tenant()->name);
    }

    public function typeCode(): ?string
    {
        return $this->context->tenant()->loadMissing('businessType:id,code')->businessType?->code;
    }

    /**
     * Whether each menu item can be offered now, keyed by item; false when the business does not have it.
     *
     * @return array<string, bool>
     */
    public function availability(): array
    {
        return [
            'book' => $this->booking->isOpen(),
            'reserve' => $this->reservations->isOpen(),
            'order' => $this->shop->isOpen(),
            'services' => $this->services()->isNotEmpty(),
            'rates' => $this->rates()->isNotEmpty(),
            'courses' => $this->courses()->isNotEmpty(),
            'offers' => $this->offers() !== [],
            'faq' => $this->faq() !== [],
            'info' => true,
            'human' => true,
        ];
    }

    /** The public website address, while the website is live; null otherwise. */
    public function website(): ?string
    {
        if (! $this->context->hasModule('website')
            || ! WebsiteConfig::query()->first()?->isPublished()
            || ! $this->entitlements->websiteOnline($this->context->tenant())) {
            return null;
        }

        $domain = $this->context->tenant()->loadMissing('primaryDomain')->primaryDomain?->domain;

        if ($domain === null) {
            return null;
        }

        $app = parse_url((string) config('app.url'));
        $port = isset($app['port']) ? ':'.$app['port'] : '';

        return ($app['scheme'] ?? 'https').'://'.$domain.$port;
    }

    /** The website's hero photo, else the logo: only JPEG or PNG, which WhatsApp shows inline. */
    public function welcomeImage(): ?Media
    {
        foreach (['hero', 'logo'] as $collection) {
            $media = Media::query()->inCollection($collection)->whereIn('mime_type', Interactive::IMAGE_TYPES)->first();

            if ($media) {
                return $media;
            }
        }

        return null;
    }

    /** The photo for a card whose item has none: the logo, else the website's hero photo (JPEG or PNG). */
    public function cardImage(): ?Media
    {
        foreach (['logo', 'hero'] as $collection) {
            $media = Media::query()->inCollection($collection)->whereIn('mime_type', Interactive::IMAGE_TYPES)->first();

            if ($media) {
                return $media;
            }
        }

        return null;
    }

    /** @return Collection<int, Service> */
    public function services(): Collection
    {
        if (! $this->context->hasEngine('service')) {
            return collect();
        }

        return Service::query()->active()->ordered()->with(['category:id,name,sort_order', 'image'])->limit(100)->get();
    }

    public function service(int $id): ?Service
    {
        return $this->services()->firstWhere('id', $id);
    }

    /**
     * Bookable resources with an hourly rate (turfs, courts, studios), when the business books without services.
     *
     * @return Collection<int, BookingResource>
     */
    public function rates(): Collection
    {
        if (! $this->context->hasEngine('booking') || $this->context->hasEngine('service')) {
            return collect();
        }

        return BookingResource::query()->active()->ordered()->whereNotNull('hourly_rate')->limit(20)->get();
    }

    public function resourceLabel(): string
    {
        return $this->bookingSettings->resourceLabels()['plural'];
    }

    /** @return Collection<int, Course> */
    public function courses(): Collection
    {
        if (! $this->context->hasEngine('education')) {
            return collect();
        }

        return Course::query()->active()->ordered()
            ->with(['image', 'batches' => fn ($query) => $query->active()->orderBy('name')])
            ->limit(50)
            ->get();
    }

    public function course(int $id): ?Course
    {
        return $this->courses()->firstWhere('id', $id);
    }

    /** @return list<array{title: string, description: ?string, price: ?string, valid_until: ?string}> */
    public function offers(): array
    {
        return array_values(array_filter(array_map(fn (array $item) => [
            'title' => trim((string) ($item['title'] ?? '')),
            'description' => filled($item['description'] ?? null) ? trim((string) $item['description']) : null,
            'price' => filled($item['price'] ?? null) ? trim((string) $item['price']) : null,
            'valid_until' => filled($item['valid_until'] ?? null) ? trim((string) $item['valid_until']) : null,
        ], $this->sectionItems('offers')), fn (array $offer) => $offer['title'] !== ''));
    }

    /** @return list<array{question: string, answer: string}> */
    public function faq(): array
    {
        return array_values(array_filter(array_map(fn (array $item) => [
            'question' => trim((string) ($item['question'] ?? '')),
            'answer' => trim((string) ($item['answer'] ?? '')),
        ], $this->sectionItems('faq')), fn (array $item) => $item['question'] !== '' && $item['answer'] !== ''));
    }

    /** @return array{phone: ?string, email: ?string, address: ?string, hours: ?string, map_url: ?string} */
    public function contact(): array
    {
        $profile = $this->context->setting('business_profile', []);
        $profile = is_array($profile) ? $profile : [];
        $place = array_values(array_filter([$profile['address'] ?? null, $profile['city'] ?? null], 'filled'));

        return [
            'phone' => filled($profile['phone'] ?? null) ? (string) $profile['phone'] : null,
            'email' => filled($profile['email'] ?? null) ? (string) $profile['email'] : null,
            'address' => $place !== [] ? implode(', ', array_unique($place)) : null,
            'hours' => filled($profile['opening_hours'] ?? null) ? (string) $profile['opening_hours'] : null,
            'map_url' => $place !== [] ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(implode(', ', [$this->name(), ...$place])) : null,
        ];
    }

    public function batchLine(Batch $batch): string
    {
        return trim($batch->name.' — '.BatchSchedule::describe($batch).($batch->fee !== null ? ' · '.$this->money($batch->fee) : ''), ' —');
    }

    public function money(mixed $amount): string
    {
        $value = (float) $amount;
        $currency = (string) $this->context->tenant()->currency;
        $symbol = self::SYMBOLS[$currency] ?? $currency.' ';

        return $symbol.(floor($value) === $value ? number_format($value) : number_format($value, 2));
    }

    public function serviceSummary(Service $service): string
    {
        return implode(' · ', array_filter([
            $service->price !== null ? $this->money($service->price) : __('Price on request'),
            $service->duration_minutes ? $service->duration_minutes.' min' : null,
        ]));
    }

    public function courseSummary(Course $course): string
    {
        return implode(' · ', array_filter([
            $course->fee !== null ? $this->money($course->fee) : null,
            $course->duration_label,
        ])) ?: Str::limit((string) $course->description, 60);
    }

    /** @return list<array<string, mixed>> the items of an enabled Offers or FAQ section */
    private function sectionItems(string $type): array
    {
        if (! $this->context->hasModule('website') || ! $this->catalog->isAvailable($type)) {
            return [];
        }

        $section = WebsiteSection::query()->where('type', $type)->where('enabled', true)->first(['id', 'type', 'configuration']);

        if (! $section) {
            return [];
        }

        $items = $this->catalog->resolve($type, $section->configuration)['items'] ?? [];

        return array_values(array_filter(is_array($items) ? $items : [], 'is_array'));
    }
}
