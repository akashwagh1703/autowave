<?php

namespace Database\Seeders;

use App\Domain\Billing\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Creates the plans in config('billing.plans') that do not exist yet. Existing plans are left alone: prices
 * and limits are edited in Super Admin → Plans. Safe to run on every deploy.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('billing.plans') as $code => $plan) {
            Plan::query()->firstOrCreate(['code' => $code], [
                'name' => $plan['name'],
                'description' => $plan['description'],
                'price_monthly' => $plan['price_monthly'],
                'price_yearly' => $plan['price_yearly'],
                'limits' => $plan['limits'],
                'is_public' => $plan['public'],
                'is_active' => true,
                'sort_order' => $plan['sort_order'],
            ]);
        }
    }
}
