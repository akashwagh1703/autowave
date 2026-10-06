<?php

namespace App\Domain\Website\Services;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Services\Availability;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Booking\Support\ResourceRates;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use App\Support\OnlineSource;
use App\Support\Phone;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Booking from the public website, on top of the booking engine (ADR-014, ADR-016).
 *
 * - Offered when the tenant has the booking engine, online booking is on and at least one active
 *   resource with working hours exists (and, with the service engine, a service it offers).
 * - With the service engine the visitor picks a service (its duration and price apply); without
 *   it a booking lasts one slot interval (e.g. an hour on a turf) and is priced from the
 *   resource's rates (ADR-020).
 * - "Any available" tries each resource offering the service in order until one is free.
 * - Minimum notice and how far ahead come from the tenant's online booking settings.
 * - BookAppointment keeps its double-booking guarantees; website bookings start pending unless
 *   online auto-confirm is on.
 */
class OnlineBooking
{
    /** @var ?Collection<int, BookingResource> */
    private ?Collection $resources = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly BookingSettings $settings,
        private readonly Availability $availability,
        private readonly BookAppointment $bookAppointment,
    ) {}

    public function isOpen(): bool
    {
        return $this->context->hasEngine('booking')
            && $this->settings->online()['enabled']
            && $this->resources()->isNotEmpty()
            && (! $this->usesServices() || $this->services()->isNotEmpty());
    }

    public function usesServices(): bool
    {
        return $this->context->hasEngine('service');
    }

    /** @return Collection<int, BookingResource> active resources that have working hours */
    public function resources(): Collection
    {
        return $this->resources ??= BookingResource::query()->active()->ordered()
            ->with(['workingHours', 'services' => fn ($query) => $query->active()])
            ->get()
            ->filter(fn (BookingResource $resource) => $resource->workingHours->isNotEmpty())
            ->values();
    }

    /** @return Collection<int, Service> active services at least one bookable resource offers */
    public function services(): Collection
    {
        if (! $this->usesServices()) {
            return collect();
        }

        $offered = $this->resources()->flatMap(fn (BookingResource $resource) => $resource->services->pluck('id'))->unique()->all();

        return Service::query()->active()->ordered()->with('category')->whereKey($offered)->get();
    }

    /** @return array{first: string, last: string} local dates visitors can book */
    public function window(): array
    {
        $today = CarbonImmutable::now(TenantTime::timezone())->startOfDay();

        return [
            'first' => $today->toDateString(),
            'last' => $today->addDays($this->settings->online()['max_days_ahead'])->toDateString(),
        ];
    }

    /**
     * Free start times on a local date for a service (or one slot interval) with a resource, or
     * with any resource offering it. Without a service, `price` is the lowest rate-based price among
     * the resources free at that time (`price_varies` when they differ).
     *
     * @return list<array{starts_at: string, time: string, price: ?string, price_varies: bool}>
     */
    public function slots(?int $serviceId, ?int $resourceId, string $date): array
    {
        $window = $this->window();

        if ($date < $window['first'] || $date > $window['last']) {
            throw ValidationException::withMessages(['date' => __('Choose a date between :first and :last.', ['first' => $window['first'], 'last' => $window['last']])]);
        }

        $service = $this->service($serviceId);
        $earliest = $this->earliestStart();
        $slots = [];

        foreach ($this->candidates($service, $resourceId) as $resource) {
            foreach ($this->availability->slots($resource, $date, $this->duration($service)) as $slot) {
                if (CarbonImmutable::parse($slot['starts_at']) < $earliest) {
                    continue;
                }

                $price = $service ? null : ResourceRates::quote($resource, CarbonImmutable::parse($slot['starts_at']), CarbonImmutable::parse($slot['ends_at']));
                $existing = $slots[$slot['starts_at']] ?? null;

                $slots[$slot['starts_at']] = [
                    'starts_at' => $slot['starts_at'],
                    'time' => $slot['time'],
                    'price' => match (true) {
                        $existing === null => $price,
                        $existing['price'] === null || $price === null => $existing['price'] ?? $price,
                        default => bccomp($price, $existing['price'], 2) < 0 ? $price : $existing['price'],
                    },
                    'price_varies' => $existing !== null && ($existing['price_varies'] || $existing['price'] !== $price),
                ];
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  string  $source  `website`, or `whatsapp` for the WhatsApp assistant (same rules)
     */
    public function book(array $input, string $source = 'website'): Appointment
    {
        $data = Validator::make($input, [
            'service_id' => [$this->usesServices() ? 'required' : 'nullable', 'integer'],
            'resource_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'service_id.required' => __('Choose a service.'),
            'starts_at.required' => __('Choose a time.'),
            'phone.regex' => __('Enter a valid phone number.'),
        ])->validate();

        if (Phone::normalize($data['phone']) === null) {
            throw ValidationException::withMessages(['phone' => __('Enter a valid phone number.')]);
        }

        $service = $this->service(isset($data['service_id']) ? (int) $data['service_id'] : null);
        $start = CarbonImmutable::parse($data['starts_at'])->utc()->startOfMinute();
        $localDate = $start->setTimezone(TenantTime::timezone())->toDateString();
        $window = $this->window();

        if ($start < $this->earliestStart() || $localDate > $window['last']) {
            throw ValidationException::withMessages(['starts_at' => __('This time can no longer be booked online. Choose another time.')]);
        }

        $status = $this->settings->online()['auto_confirm'] ? AppointmentStatus::Confirmed : AppointmentStatus::Pending;
        $raced = false;

        foreach ($this->candidates($service, isset($data['resource_id']) ? (int) $data['resource_id'] : null) as $resource) {
            if ($this->availability->problem($resource, $start, $start->addMinutes($this->duration($service))) !== null) {
                continue;
            }

            $raced = true;

            try {
                return $this->bookAppointment->handle([
                    'customer' => ['name' => trim($data['name']), 'phone' => trim($data['phone']), 'email' => filled($data['email'] ?? null) ? trim($data['email']) : null],
                    'booking_resource_id' => $resource->id,
                    'service_id' => $service?->id,
                    'starts_at' => $start,
                    'duration_minutes' => $this->duration($service),
                    'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                    'status' => $status->value,
                    'source' => OnlineSource::resolve($source),
                ]);
            } catch (ValidationException $exception) {
                if (! array_key_exists('starts_at', $exception->errors())) {
                    throw $exception;
                }
                // Taken between the check and the lock: try the next resource.
            }
        }

        throw ValidationException::withMessages(['starts_at' => $raced
            ? __('Sorry, this time was just taken. Please choose another time.')
            : __('This time is not available. Please choose another time.')]);
    }

    public function duration(?Service $service): int
    {
        return $service?->duration_minutes ?? $this->settings->slotInterval();
    }

    private function earliestStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes($this->settings->online()['min_notice_minutes']);
    }

    private function service(?int $id): ?Service
    {
        if (! $this->usesServices()) {
            return null;
        }

        return $this->services()->firstWhere('id', $id)
            ?? throw ValidationException::withMessages(['service_id' => __('Choose one of the listed services.')]);
    }

    /** @return Collection<int, BookingResource> */
    private function candidates(?Service $service, ?int $resourceId): Collection
    {
        $offering = $this->resources()->filter(fn (BookingResource $resource) => ! $service || $resource->services->contains('id', $service->id))->values();

        if ($resourceId === null) {
            if (! $this->settings->online()['allow_any_resource'] && $offering->count() > 1) {
                throw ValidationException::withMessages(['resource_id' => __('Choose who or what to book.')]);
            }

            return $offering;
        }

        $resource = $offering->firstWhere('id', $resourceId)
            ?? throw ValidationException::withMessages(['resource_id' => $service
                ? __('Choose someone who offers :service.', ['service' => $service->name])
                : __('Choose one of the listed options.')]);

        return collect([$resource]);
    }
}
