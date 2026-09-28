<?php

namespace Database\Factories;

use App\Domain\Service\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Must be used inside TenantContext::run() (tenant_id comes from the context).
 *
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'duration_minutes' => fake()->randomElement([30, 45, 60]),
            'price' => fake()->randomElement([300, 500, 800, 1200]),
            'is_active' => true,
        ];
    }
}
