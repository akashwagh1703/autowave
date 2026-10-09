<?php

namespace App\Domain\Food\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Food\Events\ReservationConfirmed;
use App\Domain\Food\Events\ReservationCreated;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Food\Models\Reservation;
use App\Domain\Food\Support\FoodSettings;
use App\Domain\Food\Support\ReservationSlots;
use App\Domain\Food\Support\TableAvailability;
use App\Models\User;
use App\Support\OnlineSource;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books a table reservation (ADR-020), from the team (`manual`), the website (`website`) or a WhatsApp chat
 * (`whatsapp`, same rules as the website).
 *
 * - Website/WhatsApp requests must fit the tenant's reservation hours, notice and horizon. A free
 *   table that seats the party is assigned so evenings cannot over-collect requests (AW-063).
 *   They start pending unless auto-confirm is on.
 * - The team can pick a table (checked against the tenant, active) and book any time; the
 *   database refuses two live reservations overlapping on one table (reservations_no_overlap).
 */
class BookReservation
{
    public function __construct(
        private readonly FoodSettings $settings,
        private readonly CreateCustomer $createCustomer,
        private readonly RecordActivity $recordActivity,
    ) {}

    /**
     * @param  array{
     *     customer_id?: ?int,
     *     customer?: ?array{name: string, phone?: ?string, email?: ?string},
     *     party_size: int,
     *     reserved_at: DateTimeInterface,
     *     duration_minutes?: ?int,
     *     dining_table_id?: ?int,
     *     notes?: ?string,
     *     source?: ?string,
     *     seated?: bool,
     * }  $data  reserved_at is an absolute instant
     */
    public function handle(array $data, ?User $actor = null): Reservation
    {
        $settings = $this->settings->reservations();
        $source = OnlineSource::resolve($data['source'] ?? null);
        $online = $source !== 'manual';
        $party = (int) ($data['party_size'] ?? 0);
        $start = CarbonImmutable::instance($data['reserved_at'])->utc()->startOfMinute();
        $duration = (int) ($data['duration_minutes'] ?? 0) ?: $settings['duration_minutes'];

        if ($party < 1 || $party > ($online ? $settings['max_party_size'] : (int) config('food.max_party_size_limit'))) {
            throw ValidationException::withMessages(['party_size' => $online
                ? __('For groups larger than :max, please call us.', ['max' => $settings['max_party_size']])
                : __('Enter the number of guests.')]);
        }

        if ($duration < 15 || $duration > 720) {
            throw ValidationException::withMessages(['duration_minutes' => __('Choose a duration between 15 minutes and 12 hours.')]);
        }

        if ($online) {
            if (! $settings['online']) {
                throw ValidationException::withMessages(['reserved_at' => __('Online table booking is not available right now.')]);
            }

            if (! ReservationSlots::isBookable($start, $settings, $party)) {
                throw ValidationException::withMessages(['reserved_at' => __('Choose one of the available times.')]);
            }
        } elseif ($start < CarbonImmutable::now()->subDay()) {
            throw ValidationException::withMessages(['reserved_at' => __('The reservation cannot be more than a day in the past.')]);
        }

        $table = null;

        if ($online) {
            $table = TableAvailability::freeTable($party, $start, $start->addMinutes($duration))
                ?? throw ValidationException::withMessages(['reserved_at' => __('Sorry, no table is free for that party size at this time. Please choose another time.')]);
        } elseif (! empty($data['dining_table_id'])) {
            $table = DiningTable::query()->active()->find($data['dining_table_id'])
                ?? throw ValidationException::withMessages(['dining_table_id' => __('Choose an active table.')]);
        }

        $status = match (true) {
            ! $online && (bool) ($data['seated'] ?? false) => ReservationStatus::Seated,
            $online && ! $settings['auto_confirm'] => ReservationStatus::Pending,
            default => ReservationStatus::Confirmed,
        };

        try {
            return DB::transaction(function () use ($data, $actor, $source, $online, $party, $start, $duration, $table, $status) {
                $customer = $this->resolveCustomer($data, $actor, $online);

                $reservation = Reservation::query()->create([
                    'customer_id' => $customer->id,
                    'dining_table_id' => $table?->id,
                    'party_size' => $party,
                    'reserved_at' => $start,
                    'ends_at' => $start->addMinutes($duration),
                    'status' => $status,
                    'source' => $source,
                    'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                    'confirmed_at' => $status !== ReservationStatus::Pending ? now() : null,
                    'seated_at' => $status === ReservationStatus::Seated ? now() : null,
                    'created_by_user_id' => $actor?->id,
                ]);

                $reservation->setRelations(['customer' => $customer, 'table' => $table]);

                $this->recordActivity->handle('reservation_created', customer: $customer, actor: $actor, metadata: [
                    ...self::summary($reservation),
                    ...($online ? ['source' => $source] : []),
                ]);

                ReservationCreated::dispatch($reservation);

                if ($status !== ReservationStatus::Pending) {
                    ReservationConfirmed::dispatch($reservation);
                }

                return $reservation;
            });
        } catch (QueryException $exception) {
            if (self::isOverlap($exception)) {
                throw ValidationException::withMessages(['dining_table_id' => __('This table is already booked at that time.')]);
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> timeline metadata */
    public static function summary(Reservation $reservation): array
    {
        return [
            'reservation_id' => $reservation->id,
            'reserved_at' => $reservation->reserved_at->toIso8601String(),
            'party_size' => $reservation->party_size,
            'table' => $reservation->table?->name,
        ];
    }

    public static function isOverlap(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? $exception->getCode()) === '23P01'
            && str_contains($exception->getMessage(), Reservation::OVERLAP_CONSTRAINT);
    }

    private function resolveCustomer(array $data, ?User $actor, bool $online): Customer
    {
        if (! $online && ! empty($data['customer_id'])) {
            return Customer::query()->find($data['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Choose a valid customer.')]);
        }

        $inline = $data['customer'] ?? null;

        if (! is_array($inline) || blank($inline['name'] ?? null)) {
            throw ValidationException::withMessages(['customer_id' => __('Choose a customer or enter their details.')]);
        }

        return CreateCustomer::duplicateOf($inline['phone'] ?? null)
            ?? $this->createCustomer->handle([
                'name' => trim($inline['name']),
                'phone' => $inline['phone'] ?? null,
                'email' => $inline['email'] ?? null,
            ], $actor, ['via' => $online ? 'online_reservation' : 'reservation']);
    }
}
