<?php

namespace App\Domain\Food\Services;

use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Food\Models\Reservation;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;

/** Live figures for the cafe dashboard widgets (reservations). Computed only with the food engine. */
class FoodMetrics
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<string>  $widgets
     * @return array<string, array{value: int|float|string, type: string, hint: string, href: ?string}>
     */
    public function for(User $user, array $widgets): array
    {
        if (! $this->context->hasEngine('food') || ! $user->can('reservations.view')) {
            return [];
        }

        $start = TenantTime::now()->startOfDay()->utc();
        $metrics = [];

        if (in_array('reservations_today', $widgets, true)) {
            $today = Reservation::query()
                ->whereIn('status', [...ReservationStatus::HOLDING, ReservationStatus::Completed->value])
                ->where('reserved_at', '>=', $start)
                ->where('reserved_at', '<', $start->copy()->addDay());

            $metrics['reservations_today'] = [
                'value' => (clone $today)->count(),
                'type' => 'number',
                'hint' => (int) (clone $today)->sum('party_size').' guests expected',
                'href' => route('reservations.index'),
            ];
        }

        return $metrics;
    }
}
