<?php

namespace Database\Factories;

use App\Domain\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Must be used inside TenantContext::run() (tenant_id comes from the context).
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'city' => fake()->city(),
            'tags' => [],
        ];
    }
}
