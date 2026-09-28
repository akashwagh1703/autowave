<?php

namespace App\Http\Controllers\App;

use App\Domain\Activity\Models\Activity;
use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Actions\UpdateAppointmentDetails;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Models\TimeOff;
use App\Domain\Booking\Services\Availability;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Customer\Models\Customer;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BookingOptions;
use App\Http\Presenters\BookingPresenter;
use App\Http\Presenters\CrmPresenter;
use App\Http\Requests\Booking\AppointmentRequest;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public const RANGES = ['upcoming', 'today', 'past', 'all'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly BookingOptions $options,
        private readonly BookingSettings $settings,
        private readonly Availability $availability,
    ) {}

    /** Day view: one column per resource with its working hours, time off and appointments. */
    public function calendar(Request $request): Response
    {
        $today = TenantTime::now()->toDateString();
        $date = self::validDate($request->query('date')) ?? $today;
        $day = CarbonImmutable::parse($date, TenantTime::timezone())->startOfDay();
        $from = $day->utc();
        $to = $day->addDay()->utc();

        $myResourceId = $this->myResourceId($request);
        $only = $request->query('resource') === 'mine' ? $myResourceId : ($request->integer('resource') ?: null);

        $appointments = Appointment::query()
            ->overlapping($from, $to)
            ->when($only, fn (Builder $query, int $id) => $query->where('booking_resource_id', $id))
            ->with(['customer', 'service'])
            ->orderBy('starts_at')
            ->get();

        $resources = BookingResource::withTrashed()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $live) => $live->whereNull('deleted_at')->where('is_active', true))
                ->orWhereIn('id', $appointments->pluck('booking_resource_id')->unique()))
            ->when($only, fn (Builder $query, int $id) => $query->whereKey($id))
            ->with('workingHours')
            ->ordered()
            ->get();

        $timeOff = TimeOff::query()
            ->whereIn('booking_resource_id', $resources->modelKeys())
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get()
            ->groupBy('booking_resource_id');

        return Inertia::render('business/appointments/Calendar', [
            'date' => $date,
            'today' => $today,
            'filter' => $request->query('resource') === 'mine' ? 'mine' : ($only ? (string) $only : null),
            'myResourceId' => $myResourceId,
            'resources' => $resources->map(fn (BookingResource $resource) => [
                ...BookingPresenter::resource($resource),
                'working_hours' => null,
                'windows' => array_map(fn (array $window) => [
                    'starts_at' => $window[0]->utc()->toIso8601String(),
                    'ends_at' => $window[1]->utc()->toIso8601String(),
                ], $this->availability->windowsOn($resource, $day)),
                'time_off' => ($timeOff[$resource->id] ?? collect())->map(fn (TimeOff $period) => BookingPresenter::timeOff($period))->values(),
            ]),
            'appointments' => $appointments->map(fn (Appointment $appointment) => BookingPresenter::appointment($appointment)),
            'summary' => [
                'booked' => $appointments->filter(fn (Appointment $a) => $a->status->blocksTime())->count(),
                'pending' => $appointments->where('status', AppointmentStatus::Pending)->count(),
                'completed' => $appointments->where('status', AppointmentStatus::Completed)->count(),
                'cancelled' => $appointments->whereIn('status', [AppointmentStatus::Cancelled, AppointmentStatus::NoShow])->count(),
            ],
            'allResources' => $this->options->resources(),
        ]);
    }

    public function index(Request $request): Response
    {
        $status = AppointmentStatus::tryFrom((string) $request->query('status'));

        $filters = [
            'search' => Str::limit(trim($request->string('search')->toString()), 100, '') ?: null,
            'range' => in_array($request->query('range'), self::RANGES, true) ? $request->query('range') : 'upcoming',
            'status' => $status?->value,
            'resource' => $request->integer('resource') ?: null,
            'service' => $request->integer('service') ?: null,
        ];

        $todayStart = TenantTime::now()->startOfDay()->utc();
        $query = Appointment::query()->with(['customer', 'resource', 'service']);

        match ($filters['range']) {
            'upcoming' => $query->where('ends_at', '>', now()),
            'today' => $query->where('starts_at', '>=', $todayStart)->where('starts_at', '<', $todayStart->copy()->addDay()),
            'past' => $query->where('ends_at', '<=', now()),
            default => null,
        };

        $query->when($status, fn (Builder $q) => $q->where('status', $status->value))
            ->when($filters['resource'], fn (Builder $q, int $id) => $q->where('booking_resource_id', $id))
            ->when($filters['service'], fn (Builder $q, int $id) => $q->where('service_id', $id));

        if ($filters['search']) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $digits = preg_replace('/\D/', '', $filters['search']);

            $query->whereHas('customer', fn (Builder $customer) => $customer->withTrashed()->where(function (Builder $q) use ($like, $digits) {
                $q->where('name', 'ilike', $like)->orWhere('phone', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $q->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            }));
        }

        in_array($filters['range'], ['upcoming', 'today'], true)
            ? $query->orderBy('starts_at')->orderBy('id')
            : $query->orderByDesc('starts_at')->orderByDesc('id');

        $appointments = $query->paginate(config('booking.per_page'))->withQueryString();

        return Inertia::render('business/appointments/Index', [
            'appointments' => CrmPresenter::paginated($appointments, fn (Appointment $appointment) => BookingPresenter::appointment($appointment)),
            'filters' => $filters,
            'counts' => [
                'today' => Appointment::query()->blocking()->where('starts_at', '>=', $todayStart)->where('starts_at', '<', $todayStart->copy()->addDay())->count(),
                'pending' => Appointment::query()->where('status', AppointmentStatus::Pending)->where('ends_at', '>', now())->count(),
            ],
            'statuses' => BookingPresenter::statuses(),
            'resources' => $this->options->resources(),
            'services' => $this->options->services(),
        ]);
    }

    public function create(Request $request): Response
    {
        $customer = $request->integer('customer') ? Customer::query()->find($request->integer('customer')) : null;
        $time = $request->query('time');

        return Inertia::render('business/appointments/Create', [
            'resources' => $this->options->resources(),
            'services' => $this->options->services(),
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone] : null,
            'prefill' => [
                'booking_resource_id' => $request->integer('resource') ?: null,
                'service_id' => $request->integer('service') ?: null,
                'date' => self::validDate($request->query('date')) ?? TenantTime::now()->toDateString(),
                'time' => is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : null,
            ],
            'autoConfirm' => $this->settings->autoConfirm(),
            'today' => TenantTime::now()->toDateString(),
        ]);
    }

    public function store(AppointmentRequest $request, BookAppointment $bookAppointment): RedirectResponse
    {
        $appointment = $bookAppointment->handle($request->bookingData(), $request->user());

        return to_route('appointments.show', $appointment)->with('success', __('Appointment booked for :name.', ['name' => $appointment->customer->name]));
    }

    public function show(Request $request, Appointment $appointment): Response
    {
        $appointment->load(['customer', 'resource', 'service', 'creator:id,name']);
        $started = $appointment->starts_at->isPast();

        return Inertia::render('business/appointments/Show', [
            'appointment' => BookingPresenter::appointment($appointment),
            'transitions' => collect($appointment->status->allowedTransitions())
                ->map(fn (AppointmentStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'available' => ! $status->requiresStart() || $started,
                ])
                ->values(),
            'activities' => Activity::query()
                ->where('appointment_id', $appointment->id)
                ->with('user:id,name')
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->get()
                ->map(fn (Activity $activity) => CrmPresenter::activity($activity)),
            'resources' => $appointment->status->isActive() ? $this->options->resources($appointment->booking_resource_id) : [],
        ]);
    }

    public function update(Request $request, Appointment $appointment, UpdateAppointmentDetails $updateDetails): RedirectResponse
    {
        $validated = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $updateDetails->handle($appointment, $validated, $request->user());

        return back()->with('success', __('Appointment updated.'));
    }

    /** Free start times for a resource on a local date (JSON, used by the booking and reschedule forms). */
    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'resource' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'service' => ['nullable', 'integer'],
            'duration' => ['nullable', 'integer', 'min:'.config('booking.duration.min'), 'max:'.config('booking.duration.max')],
            'ignore' => ['nullable', 'integer'],
        ]);

        $resource = BookingResource::query()->with('workingHours')->findOrFail($validated['resource']);
        $service = ! empty($validated['service']) && $this->context->hasEngine('service') ? Service::query()->find($validated['service']) : null;
        $duration = (int) ($validated['duration'] ?? $service?->duration_minutes ?? 0);
        $ignore = ! empty($validated['ignore']) ? Appointment::query()->whereKey($validated['ignore'])->value('id') : null;
        $day = CarbonImmutable::parse($validated['date'], TenantTime::timezone())->startOfDay();

        return response()->json([
            'slots' => $duration > 0 ? $this->availability->slots($resource, $validated['date'], $duration, $ignore) : [],
            'windows' => array_map(fn (array $window) => ['starts_at' => $window[0]->format('H:i'), 'ends_at' => $window[1]->format('H:i')], $this->availability->windowsOn($resource, $day)),
            'duration' => $duration,
            'interval' => $this->settings->slotInterval(),
        ]);
    }

    /** Customer lookup for the booking form (JSON). */
    public function customers(Request $request): JsonResponse
    {
        $search = Str::limit(trim($request->string('search')->toString()), 100, '');

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = preg_replace('/\D/', '', $search);

        $customers = Customer::query()
            ->where(function (Builder $query) use ($like, $digits) {
                $query->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('phone', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $query->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone', 'email']);

        return response()->json(['data' => $customers->map(fn (Customer $customer) => $customer->only(['id', 'name', 'phone', 'email']))]);
    }

    private function myResourceId(Request $request): ?int
    {
        $membershipId = TenantUser::query()
            ->where('tenant_id', $this->context->tenant()->id)
            ->where('user_id', $request->user()->id)
            ->value('id');

        return $membershipId ? BookingResource::query()->where('tenant_user_id', $membershipId)->value('id') : null;
    }

    private static function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
