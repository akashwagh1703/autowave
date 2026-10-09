<?php

namespace App\Domain\Website\Services;

use App\Domain\Food\Actions\BookReservation;
use App\Domain\Food\Models\Reservation;
use App\Domain\Food\Support\FoodSettings;
use App\Domain\Food\Support\ReservationSlots;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteSection;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use App\Support\OnlineSource;
use App\Support\Phone;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Table reservation requests from the public website (ADR-020). Open when the tenant has the food
 * engine, the reservation section is on and online reservations are enabled. Visitors pick a date,
 * a time from ReservationSlots and a party size; a free fitting table is held (AW-063).
 */
class OnlineReservations
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FoodSettings $settings,
        private readonly BookReservation $book,
    ) {}

    public function isOpen(): bool
    {
        return $this->context->hasEngine('food')
            && $this->settings->reservations()['online']
            && WebsiteSection::query()->where('type', 'reservation')->where('enabled', true)->exists();
    }

    /** @return list<array{starts_at: string, time: string}> */
    public function slots(string $date, ?int $partySize = null): array
    {
        return ReservationSlots::forDate($date, $this->settings->reservations(), $partySize);
    }

    /** @return array<string, mixed> public settings for the reservation form */
    public function props(): array
    {
        $settings = $this->settings->reservations();
        $today = TenantTime::now()->startOfDay();

        return [
            'max_party_size' => $settings['max_party_size'],
            'first_date' => $today->toDateString(),
            'last_date' => $today->copy()->addDays($settings['max_days_ahead'])->toDateString(),
            'auto_confirm' => $settings['auto_confirm'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  string  $source  `website`, or `whatsapp` for the WhatsApp assistant (same rules)
     */
    public function book(array $input, string $source = 'website'): Reservation
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'party_size' => ['required', 'integer', 'min:1'],
            'starts_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'phone.regex' => __('Enter a valid phone number.'),
            'starts_at.required' => __('Choose a time.'),
        ])->validate();

        if (Phone::normalize($data['phone']) === null) {
            throw ValidationException::withMessages(['phone' => __('Enter a valid phone number.')]);
        }

        try {
            return $this->book->handle([
                'customer' => ['name' => trim($data['name']), 'phone' => trim($data['phone']), 'email' => filled($data['email'] ?? null) ? trim($data['email']) : null],
                'party_size' => (int) $data['party_size'],
                'reserved_at' => CarbonImmutable::parse($data['starts_at']),
                'notes' => $data['notes'] ?? null,
                'source' => OnlineSource::resolve($source),
            ]);
        } catch (ValidationException $exception) {
            // The website form calls the time `starts_at`.
            $errors = $exception->errors();

            if (isset($errors['reserved_at'])) {
                $errors['starts_at'] = $errors['reserved_at'];
                unset($errors['reserved_at']);
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
