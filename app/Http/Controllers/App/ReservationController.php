<?php

namespace App\Http\Controllers\App;

use App\Domain\Activity\Models\Activity;
use App\Domain\Customer\Support\CustomerLookup;
use App\Domain\Food\Actions\BookReservation;
use App\Domain\Food\Actions\ChangeReservationStatus;
use App\Domain\Food\Actions\UpdateReservation;
use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Food\Models\Reservation;
use App\Domain\Food\Support\FoodSettings;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CrmPresenter;
use App\Http\Presenters\FoodPresenter;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Table reservations: the day list, booking by the team, changes and status actions. */
class ReservationController extends Controller
{
    public const VIEWS = ['live', 'all', 'cancelled'];

    public function index(Request $request, FoodSettings $settings): Response
    {
        $timezone = TenantTime::timezone();
        $today = TenantTime::now()->toDateString();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? (string) $request->query('date') : $today;
        $view = in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : 'live';

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone) ?: CarbonImmutable::now($timezone)->startOfDay();
        $query = Reservation::query()->with(['customer', 'table'])
            ->where('reserved_at', '>=', $start->utc())
            ->where('reserved_at', '<', $start->addDay()->utc());

        match ($view) {
            'live' => $query->whereIn('status', [...ReservationStatus::HOLDING, ReservationStatus::Completed->value]),
            'cancelled' => $query->whereIn('status', [ReservationStatus::Cancelled->value, ReservationStatus::NoShow->value]),
            default => null,
        };

        return Inertia::render('business/reservations/Index', [
            'reservations' => $query->orderBy('reserved_at')->orderBy('id')->get()->map(fn (Reservation $reservation) => FoodPresenter::reservation($reservation)),
            'filters' => ['date' => $start->toDateString(), 'view' => $view],
            'today' => $today,
            'counts' => [
                'pending' => Reservation::query()->where('status', ReservationStatus::Pending)->where('ends_at', '>', now())->count(),
                'guests' => (int) Reservation::query()->whereIn('status', ReservationStatus::HOLDING)
                    ->where('reserved_at', '>=', $start->utc())->where('reserved_at', '<', $start->addDay()->utc())->sum('party_size'),
            ],
            'tables' => $this->tables(),
            'defaults' => ['duration_minutes' => $settings->reservations()['duration_minutes']],
            'durationOptions' => config('food.duration_options'),
        ]);
    }

    public function store(Request $request, BookReservation $book): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['nullable', 'required_without:customer_id', 'string', 'min:2', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'reserved_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:720'],
            'party_size' => ['required', 'integer', 'min:1', 'max:'.config('food.max_party_size_limit')],
            'dining_table_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'seated' => ['nullable', 'boolean'],
        ], [
            'customer.name.required_without' => __('Choose a customer or enter their name.'),
            'customer.phone.regex' => __('Enter a valid phone number.'),
        ]);

        $reservation = $book->handle([
            ...$validated,
            'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'reserved_at' => TenantTime::parse($validated['reserved_at']),
            'source' => 'manual',
        ], $request->user());

        return to_route('reservations.index', ['date' => $reservation->reserved_at->setTimezone(TenantTime::timezone())->toDateString()])
            ->with('success', __('Table booked for :name.', ['name' => $reservation->customer->name]));
    }

    public function show(Reservation $reservation): Response
    {
        $reservation->load(['customer', 'table', 'creator:id,name']);

        return Inertia::render('business/reservations/Show', [
            'reservation' => [
                ...FoodPresenter::reservation($reservation),
                'creator' => $reservation->creator ? ['id' => $reservation->creator->id, 'name' => $reservation->creator->name] : null,
            ],
            'tables' => $this->tables(),
            'durationOptions' => config('food.duration_options'),
            'activities' => Activity::query()
                ->where('customer_id', $reservation->customer_id)
                ->whereRaw("metadata->>'reservation_id' = ?", [(string) $reservation->id])
                ->with('user:id,name')
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->get()
                ->map(fn (Activity $activity) => CrmPresenter::activity($activity)),
        ]);
    }

    public function update(Request $request, Reservation $reservation, UpdateReservation $update): RedirectResponse
    {
        $validated = $request->validate([
            'reserved_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:720'],
            'party_size' => ['required', 'integer', 'min:1', 'max:'.config('food.max_party_size_limit')],
            'dining_table_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $update->handle($reservation, [...$validated, 'reserved_at' => TenantTime::parse($validated['reserved_at'])], $request->user());

        return back()->with('success', __('Reservation updated.'));
    }

    public function status(Request $request, Reservation $reservation, ChangeReservationStatus $changeStatus): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'seated', 'completed', 'cancelled', 'no_show'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = ReservationStatus::from($validated['status']);
        $changeStatus->handle($reservation, $status, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', match ($status) {
            ReservationStatus::Confirmed => __('Reservation confirmed.'),
            ReservationStatus::Seated => __('Guests seated.'),
            ReservationStatus::Completed => __('Reservation completed.'),
            ReservationStatus::Cancelled => __('Reservation cancelled.'),
            default => __('Marked as a no-show.'),
        });
    }

    /** Customer lookup for the booking form (JSON). */
    public function customers(Request $request): JsonResponse
    {
        Gate::authorize('reservations.manage');

        return response()->json(['data' => CustomerLookup::search($request->string('search')->toString())]);
    }

    /** @return list<array<string, mixed>> */
    private function tables(): array
    {
        return DiningTable::query()->active()->ordered()->get()->map(fn (DiningTable $table) => FoodPresenter::table($table))->all();
    }
}
