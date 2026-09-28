<?php

namespace Database\Factories;

use App\Domain\Booking\Models\BookingResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Must be used inside TenantContext::run() (tenant_id comes from the context). Creates no working
 * hours; use SaveBookingResource or withHours() for a bookable resource.
 *
 * @extends Factory<BookingResource>
 */
class BookingResourceFactory extends Factory
{
    protected $model = BookingResource::class;

    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'color' => fake()->randomElement(['#6366f1', '#0ea5e9', '#16a34a', '#f59e0b', '#db2777']),
            'is_active' => true,
        ];
    }

    /**
     * @param  list<int>  $weekdays  ISO weekdays
     */
    public function withHours(array $weekdays = [1, 2, 3, 4, 5, 6, 7], string $from = '09:00', string $to = '18:00'): static
    {
        return $this->afterCreating(function (BookingResource $resource) use ($weekdays, $from, $to) {
            $resource->workingHours()->createMany(array_map(
                fn (int $weekday) => ['weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to],
                $weekdays,
            ));
        });
    }
}
