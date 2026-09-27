<?php

namespace Database\Factories;

use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Bare tenant rows (no roles, modules or domain). Use CreateTenant for fully provisioned tenants.
 *
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => TenantStatus::Active,
            'is_internal' => false,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }
}
