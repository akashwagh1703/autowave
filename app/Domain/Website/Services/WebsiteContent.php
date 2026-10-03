<?php

namespace App\Domain\Website\Services;

use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Booking\Support\ResourceRates;
use App\Domain\Commerce\Models\Product;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Support\BatchSchedule;
use App\Domain\Files\Models\Attachment;
use App\Domain\Media\Models\Media;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteConfig;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Support\SectionCatalog;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Everything the public website renders, read from the tenant's own records at request time
 * (master prompt §54: one source of truth). Only public data leaves this class: no ids of
 * members, no customer data, no internal notes.
 *
 * Sections with a data source are left out while they have nothing to show, and so are sections
 * the tenant's engines or modules do not support.
 */
class WebsiteContent
{
    private const NO_FILES = ['video' => null, 'brochures' => []];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SectionCatalog $catalog,
        private readonly OnlineBooking $booking,
        private readonly BookingSettings $bookingSettings,
        private readonly OnlineShop $shop,
        private readonly OnlineReservations $reservations,
    ) {}

    /** @return array<string, mixed> */
    public function page(WebsiteConfig $config): array
    {
        $tenant = $this->context->tenant()->loadMissing('businessType:id,name');
        $branding = $this->context->setting('branding', []);
        $profile = $this->context->setting('business_profile', []);
        $name = $branding['business_name'] ?? $tenant->name;

        $business = [
            'name' => $name,
            'business_type' => $tenant->businessType?->name,
            'tagline' => $branding['tagline'] ?? null,
            'description' => $profile['description'] ?? null,
            'primary_color' => $config->theme['primary_color'] ?? $branding['primary_color'] ?? null,
            'logo' => Media::query()->inCollection('logo')->first()?->url(),
        ];

        $sections = $this->sections($business);
        $types = array_column($sections, 'type');

        return [
            'business' => $business,
            'template' => ['code' => $config->template->code, ...$config->template->theme()],
            'seo' => [
                'title' => ($config->seo['title'] ?? null) ?: $name,
                'description' => ($config->seo['description'] ?? null) ?: ($business['tagline'] ?? $business['description']),
                'image' => $business['logo'],
            ],
            'contact' => $this->contact($profile, $name),
            'social' => collect(config('website.social'))
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label, 'url' => $profile['social'][$key] ?? null])
                ->filter(fn (array $link) => filled($link['url']))
                ->values()
                ->all(),
            'locale' => ['currency' => $tenant->currency, 'timezone' => $tenant->timezone],
            'sections' => $sections,
            'booking' => in_array('booking', $types, true) ? $this->bookingProps() : null,
            'shop' => in_array('products', $types, true) && $this->shop->isOpen() ? $this->shop->props() : null,
            'reservation' => in_array('reservation', $types, true) ? $this->reservations->props() : null,
            'enquiry' => in_array('contact', $types, true) ? $this->enquiryProps($sections) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $business
     * @return list<array{id: int, type: string, config: array<string, mixed>, data: mixed}>
     */
    private function sections(array $business): array
    {
        $rows = WebsiteSection::query()->where('enabled', true)->get(['id', 'type', 'sort_order', 'configuration']);
        $sections = [];

        foreach (SectionCatalog::sorted($rows) as $section) {
            if (! $this->catalog->isAvailable($section->type)) {
                continue;
            }

            $config = $this->catalog->resolve($section->type, $section->configuration);
            $data = $this->data($section, $config, $business);

            if ($data === false) {
                continue;
            }

            $sections[] = ['id' => $section->id, 'type' => $section->type, 'config' => $config, 'data' => $data];
        }

        return $sections;
    }

    /**
     * The records a section shows; false leaves the section out.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $business
     */
    private function data(WebsiteSection $section, array $config, array $business): mixed
    {
        $source = SectionCatalog::definition($section->type)['data'] ?? null;

        $data = match ($section->type) {
            'hero' => ['image' => Media::query()->inCollection('hero')->first()?->url()],
            'about' => filled($config['body'] ?? null) || filled($business['description']) ? [] : false,
            'booking' => $this->booking->isOpen() ? [] : false,
            'reservation' => $this->reservations->isOpen() ? [] : false,
            default => match ($source) {
                'courses' => $this->courses(),
                'services' => $this->services(),
                'team' => $this->team(),
                'gallery' => Media::query()->inCollection('gallery')->get()
                    ->map(fn (Media $media) => ['url' => $media->url(), 'alt' => $media->alt, 'width' => $media->width, 'height' => $media->height])
                    ->all(),
                'items' => $config['items'] ?? [],
                'products' => $this->products(),
                'videos' => $this->sectionFiles($section, 'video')->map(fn (Attachment $file) => self::video($file))->all(),
                'downloads' => $this->sectionFiles($section, 'document')->map(fn (Attachment $file) => self::brochure($file))->all(),
                // Filled by later phases; until then these sections stay hidden.
                'packages', 'reviews' => [],
                default => [],
            },
        };

        return $source !== null && $data === [] ? false : $data;
    }

    /** @return list<array{name: ?string, services: list<array<string, mixed>>}> */
    private function services(): array
    {
        $bookable = $this->booking->isOpen() ? $this->booking->services()->pluck('id')->all() : [];
        $services = Service::query()->active()->with('category')->get();
        $files = $this->files(Service::class, $services->modelKeys());

        return $services
            ->sortBy(fn (Service $service) => [$service->category === null ? 1 : 0, $service->category?->sort_order ?? 0, $service->category?->name ?? '', $service->sort_order, $service->name])
            ->groupBy(fn (Service $service) => $service->category?->name ?? '')
            ->map(fn ($services, string $category) => [
                'name' => $category !== '' ? $category : null,
                'services' => $services->map(fn (Service $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'description' => $service->description,
                    'duration_minutes' => $service->duration_minutes,
                    'price' => $service->price !== null ? (float) $service->price : null,
                    'bookable' => in_array($service->id, $bookable, true),
                    ...($files[$service->id] ?? self::NO_FILES),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Active products by category. Stock counts stay private: visitors only see whether a product
     * is in stock and the most they can order.
     *
     * @return list<array{name: ?string, products: list<array<string, mixed>>}>
     */
    private function products(): array
    {
        if (! $this->context->hasEngine('commerce')) {
            return [];
        }

        $max = (int) config('commerce.limits.max_quantity');
        $products = Product::query()->active()->with(['category', 'image'])->get();
        $files = $this->files(Product::class, $products->modelKeys());

        return $products
            ->sortBy(fn (Product $product) => [$product->category === null ? 1 : 0, $product->category?->sort_order ?? 0, $product->category?->name ?? '', $product->sort_order, $product->name])
            ->groupBy(fn (Product $product) => $product->category?->name ?? '')
            ->map(fn ($products, string $category) => [
                'name' => $category !== '' ? $category : null,
                'products' => $products->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => (string) $product->price,
                    'compare_at_price' => $product->compare_at_price !== null ? (string) $product->compare_at_price : null,
                    'image' => $product->image?->url(),
                    'in_stock' => $product->isInStock() && $product->is_available,
                    'max_quantity' => $product->track_stock ? min($product->available(), $max) : $max,
                    'food_type' => $product->food_type,
                    ...($files[$product->id] ?? self::NO_FILES),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Active courses with their active batches (public timings and fees only; no teacher or room).
     *
     * @return list<array<string, mixed>>
     */
    private function courses(): array
    {
        if (! $this->context->hasEngine('education')) {
            return [];
        }

        $courses = Course::query()->active()->ordered()
            ->with(['batches' => fn ($query) => $query->active()->orderBy('name')])
            ->get();
        $files = $this->files(Course::class, $courses->modelKeys());

        return $courses
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'name' => $course->name,
                'description' => $course->description,
                'duration_label' => $course->duration_label,
                'fee' => $course->fee !== null ? (string) $course->fee : null,
                'batches' => $course->batches->map(fn (Batch $batch) => [
                    'name' => $batch->name,
                    'schedule' => BatchSchedule::describe($batch),
                    'starts_on' => $batch->starts_on?->toDateString(),
                    'fee' => $batch->fee !== null ? (string) $batch->fee : null,
                ])->values()->all(),
                ...($files[$course->id] ?? self::NO_FILES),
            ])
            ->all();
    }

    /**
     * Public videos and brochures of the listed records, keyed by record id. Only files marked public
     * (catalog uploads) are read; private documents never reach the website.
     *
     * @param  class-string<Model>  $model
     * @param  list<int>  $ids
     * @return array<int, array{video: ?array{url: string, type: string, title: ?string}, brochures: list<array{name: string, url: string, extension: string, size_bytes: int}>}>
     */
    private function files(string $model, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Attachment::query()
            ->where('attachable_type', (new $model)->getMorphClass())
            ->whereIn('attachable_id', $ids)
            ->where('visibility', Attachment::PUBLIC)
            ->orderBy('id')
            ->get()
            ->groupBy('attachable_id')
            ->map(function ($files) {
                $video = $files->firstWhere('kind', 'video');

                return [
                    'video' => $video ? self::video($video) : null,
                    'brochures' => $files->where('kind', 'document')->map(fn (Attachment $file) => self::brochure($file))->values()->all(),
                ];
            })
            ->all();
    }

    /**
     * Public files of a Video or Downloads section, oldest first.
     *
     * @return Collection<int, Attachment>
     */
    private function sectionFiles(WebsiteSection $section, string $kind): Collection
    {
        return Attachment::query()
            ->where('attachable_type', $section->getMorphClass())
            ->where('attachable_id', $section->id)
            ->where('visibility', Attachment::PUBLIC)
            ->where('kind', $kind)
            ->orderBy('id')
            ->get();
    }

    /** @return array{url: string, type: string, title: ?string} */
    private static function video(Attachment $file): array
    {
        return ['url' => $file->publicUrl(), 'type' => $file->mime_type, 'title' => $file->title];
    }

    /** @return array{name: string, url: string, extension: string, size_bytes: int} */
    private static function brochure(Attachment $file): array
    {
        return [
            'name' => $file->title ?: pathinfo($file->original_name, PATHINFO_FILENAME),
            'url' => $file->publicUrl(),
            'extension' => Str::upper(pathinfo($file->path, PATHINFO_EXTENSION)),
            'size_bytes' => (int) $file->size_bytes,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function team(): array
    {
        return BookingResource::query()->active()->ordered()
            ->with(['services' => fn ($query) => $query->active()->ordered()])
            ->get()
            ->map(fn (BookingResource $resource) => [
                'id' => $resource->id,
                'name' => $resource->name,
                'description' => $resource->description,
                'color' => $resource->color,
                'services' => $this->context->hasEngine('service') ? $resource->services->pluck('name')->all() : [],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function contact(array $profile, string $name): array
    {
        $whatsapp = Phone::normalize($profile['whatsapp'] ?? null);
        $place = array_values(array_filter([$profile['address'] ?? null, $profile['city'] ?? null], 'filled'));

        return [
            'phone' => $profile['phone'] ?? null,
            'phone_href' => filled($profile['phone'] ?? null) ? 'tel:'.preg_replace('/[^\d+]/', '', $profile['phone']) : null,
            'email' => $profile['email'] ?? null,
            'address' => $profile['address'] ?? null,
            'city' => $profile['city'] ?? null,
            'opening_hours' => $profile['opening_hours'] ?? null,
            'map_url' => $place ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode(implode(', ', [$name, ...$place])) : null,
            'whatsapp' => $profile['whatsapp'] ?? null,
            'whatsapp_url' => $whatsapp
                ? 'https://wa.me/'.ltrim($whatsapp, '+').'?text='.rawurlencode(str_replace(':business', $name, (string) config('website.whatsapp_message')))
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function bookingProps(): array
    {
        $online = $this->bookingSettings->online();

        return [
            'uses_services' => $this->booking->usesServices(),
            'allow_any_resource' => $online['allow_any_resource'],
            'window' => $this->booking->window(),
            'duration_minutes' => $this->booking->duration(null),
            'resource_label' => $this->bookingSettings->resourceLabels(),
            'services' => $this->booking->services()->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'category' => $service->category?->name,
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price !== null ? (float) $service->price : null,
            ])->values()->all(),
            'resources' => $this->booking->resources()->map(fn (BookingResource $resource) => [
                'id' => $resource->id,
                'name' => $resource->name,
                'color' => $resource->color,
                'service_ids' => $resource->services->pluck('id')->all(),
                ...($this->booking->usesServices() ? [] : ResourceRates::summary($resource)),
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<array{type: string, config: array<string, mixed>}>  $sections
     * @return array{enabled: bool, interests: list<string>}
     */
    private function enquiryProps(array $sections): array
    {
        $contact = collect($sections)->firstWhere('type', 'contact');

        return [
            'enabled' => $this->context->hasModule('leads') && (bool) ($contact['config']['show_form'] ?? false),
            'interests' => match (true) {
                $this->context->hasEngine('service') => Service::query()->active()->ordered()->pluck('name')->all(),
                $this->context->hasEngine('education') => Course::query()->active()->ordered()->pluck('name')->all(),
                default => [],
            },
        ];
    }
}
