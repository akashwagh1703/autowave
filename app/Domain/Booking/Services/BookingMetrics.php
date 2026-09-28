<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;

/**
 * Live figures for the booking dashboard widgets. Computed only when the booking engine is on and
 * the user can view appointments; money figures additionally need `reports.view`.
 */
class BookingMetrics
{
    public const NO_SHOW_DAYS = 30;

    public const CANCELLATION_DAYS = 7;

    public const SALES_DAYS = 30;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Availability $availability,
        private readonly BookingSettings $settings,
    ) {}

    /**
     * @param  list<string>  $widgets
     * @return array<string, array{value: int|float|string, type: string, hint: string, href: ?string}>
     */
    public function for(User $user, array $widgets): array
    {
        if (! $this->context->hasEngine('booking') || ! $user->can('appointments.view')) {
            return [];
        }

        $money = $user->can('reports.view');
        $todayStart = TenantTime::now()->startOfDay()->utc();
        $todayEnd = TenantTime::now()->endOfDay()->utc();
        $metrics = [];

        foreach ($widgets as $widget) {
            $metric = match (true) {
                in_array($widget, ['appointments_today', 'bookings_today'], true) => [
                    'value' => Appointment::query()->blocking()->whereBetween('starts_at', [$todayStart, $todayEnd])->count(),
                    'type' => 'number',
                    'hint' => 'Booked for today',
                    'href' => route('appointments.calendar'),
                ],
                $widget === 'available_slots' => [
                    'value' => $this->freeSlotsToday(),
                    'type' => 'number',
                    'hint' => 'Free '.$this->settings->slotInterval().'-minute slots left today',
                    'href' => route('appointments.calendar'),
                ],
                $widget === 'no_shows' => [
                    'value' => Appointment::query()->where('status', AppointmentStatus::NoShow)->where('starts_at', '>=', now()->subDays(self::NO_SHOW_DAYS))->count(),
                    'type' => 'number',
                    'hint' => 'Last '.self::NO_SHOW_DAYS.' days',
                    'href' => route('appointments.index', ['status' => 'no_show', 'range' => 'past']),
                ],
                $widget === 'cancellations' => [
                    'value' => Appointment::query()->where('status', AppointmentStatus::Cancelled)->where('cancelled_at', '>=', now()->subDays(self::CANCELLATION_DAYS))->count(),
                    'type' => 'number',
                    'hint' => 'Last '.self::CANCELLATION_DAYS.' days',
                    'href' => route('appointments.index', ['status' => 'cancelled', 'range' => 'all']),
                ],
                $widget === 'repeat_customers' => [
                    'value' => Appointment::query()
                        ->where('status', AppointmentStatus::Completed)
                        ->groupBy('customer_id')
                        ->havingRaw('count(*) >= 2')
                        ->select('customer_id')
                        ->get()
                        ->count(),
                    'type' => 'number',
                    'hint' => 'Customers with 2+ completed visits',
                    'href' => null,
                ],
                $money && $widget === 'revenue_today' => [
                    'value' => (string) (Appointment::query()->where('status', AppointmentStatus::Completed)->whereBetween('starts_at', [$todayStart, $todayEnd])->sum('price') ?? 0),
                    'type' => 'currency',
                    'hint' => 'Completed appointments today',
                    'href' => route('appointments.index', ['status' => 'completed', 'range' => 'today']),
                ],
                $money && $widget === 'service_sales' => [
                    'value' => (string) (Appointment::query()->where('status', AppointmentStatus::Completed)->whereNotNull('service_id')->where('starts_at', '>=', now()->subDays(self::SALES_DAYS))->sum('price') ?? 0),
                    'type' => 'currency',
                    'hint' => 'Completed services, last '.self::SALES_DAYS.' days',
                    'href' => route('appointments.index', ['status' => 'completed', 'range' => 'past']),
                ],
                default => null,
            };

            if ($metric) {
                $metrics[$widget] = $metric;
            }
        }

        return $metrics;
    }

    private function freeSlotsToday(): int
    {
        $date = TenantTime::now()->toDateString();
        $interval = $this->settings->slotInterval();

        return BookingResource::query()->active()->with('workingHours')->get()
            ->sum(fn (BookingResource $resource) => count($this->availability->slots($resource, $date, $interval)));
    }
}
