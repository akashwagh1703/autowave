<?php

namespace App\Http\Controllers\App;

use App\Domain\Booking\Actions\AddTimeOff;
use App\Domain\Booking\Actions\DeleteBookingResource;
use App\Domain\Booking\Actions\RemoveTimeOff;
use App\Domain\Booking\Actions\SaveBookingResource;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Models\TimeOff;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BookingOptions;
use App\Http\Presenters\BookingPresenter;
use App\Http\Requests\Booking\BookingResourceRequest;
use App\Support\TenantTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bookable resources — staff, doctors, turfs, rooms — named by the business type's label.
 */
class BookingResourceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly BookingOptions $options,
        private readonly BookingSettings $settings,
    ) {}

    public function index(): Response
    {
        $weekStart = TenantTime::now()->startOfWeek()->utc();
        $weekEnd = TenantTime::now()->endOfWeek()->utc();

        $bookedThisWeek = Appointment::query()
            ->blocking()
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<=', $weekEnd)
            ->groupBy('booking_resource_id')
            ->selectRaw('booking_resource_id, count(*) as aggregate')
            ->pluck('aggregate', 'booking_resource_id');

        return Inertia::render('business/resources/Index', [
            'resources' => BookingResource::query()
                ->with(['member.user:id,name', 'workingHours'])
                ->withCount('services')
                ->ordered()
                ->get()
                ->map(fn (BookingResource $resource) => [
                    ...BookingPresenter::resource($resource),
                    'services_count' => $resource->services_count,
                    'appointments_this_week' => (int) ($bookedThisWeek[$resource->id] ?? 0),
                ]),
            'servicesEnabled' => $this->context->hasEngine('service'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('business/resources/Create', [
            ...$this->formOptions(),
            'defaultHours' => $this->settings->defaultHours(),
        ]);
    }

    public function store(BookingResourceRequest $request, SaveBookingResource $saveResource): RedirectResponse
    {
        $resource = $saveResource->handle($this->withoutDisabledServices($request->resourceData()));

        return to_route('resources.show', $resource)->with('success', __(':name added.', ['name' => $resource->name]));
    }

    public function show(BookingResource $bookingResource): Response
    {
        $bookingResource->load(['member.user:id,name', 'workingHours', 'services' => fn ($query) => $query->ordered()]);

        return Inertia::render('business/resources/Show', [
            'resource' => BookingPresenter::resource($bookingResource),
            'services' => $bookingResource->services->map(fn ($service) => BookingPresenter::service($service)),
            'upcoming' => $bookingResource->appointments()
                ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
                ->where('ends_at', '>', now())
                ->with(['customer', 'service'])
                ->orderBy('starts_at')
                ->limit(10)
                ->get()
                ->map(fn (Appointment $appointment) => BookingPresenter::appointment($appointment)),
            'timeOff' => $bookingResource->timeOff()
                ->where('ends_at', '>', now()->subDays(30))
                ->orderBy('starts_at')
                ->get()
                ->map(fn (TimeOff $timeOff) => BookingPresenter::timeOff($timeOff)),
            'servicesEnabled' => $this->context->hasEngine('service'),
        ]);
    }

    public function edit(BookingResource $bookingResource): Response
    {
        $bookingResource->load(['services:id', 'workingHours']);

        return Inertia::render('business/resources/Edit', [
            'resource' => BookingPresenter::resource($bookingResource),
            ...$this->formOptions(),
        ]);
    }

    public function update(BookingResourceRequest $request, BookingResource $bookingResource, SaveBookingResource $saveResource): RedirectResponse
    {
        $saveResource->handle($this->withoutDisabledServices($request->resourceData()), $bookingResource);

        return to_route('resources.show', $bookingResource)->with('success', __('Saved.'));
    }

    public function destroy(BookingResource $bookingResource, DeleteBookingResource $deleteResource): RedirectResponse
    {
        $deleteResource->handle($bookingResource);

        return to_route('resources.index')->with('success', __(':name deleted.', ['name' => $bookingResource->name]));
    }

    public function storeTimeOff(Request $request, BookingResource $bookingResource, AddTimeOff $addTimeOff): RedirectResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:150'],
        ]);

        $addTimeOff->handle(
            $bookingResource,
            TenantTime::parse($validated['starts_at']),
            TenantTime::parse($validated['ends_at']),
            $validated['reason'] ?? null,
            $request->user(),
        );

        return back()->with('success', __('Time off added.'));
    }

    public function destroyTimeOff(BookingResource $bookingResource, TimeOff $timeOff, RemoveTimeOff $removeTimeOff): RedirectResponse
    {
        abort_unless($timeOff->booking_resource_id === $bookingResource->id, 404);

        $removeTimeOff->handle($timeOff);

        return back()->with('success', __('Time off removed.'));
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'members' => $this->options->members(),
            'services' => $this->options->services(),
            'servicesEnabled' => $this->context->hasEngine('service'),
        ];
    }

    /** Without the service engine, the service list is not shown, so it must not be cleared either. */
    private function withoutDisabledServices(array $data): array
    {
        if (! $this->context->hasEngine('service')) {
            unset($data['service_ids']);
        }

        return $data;
    }
}
