<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data every environment needs (catalogue, RBAC templates, plans). Also used by tests.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CatalogSeeder::class,
            RbacSeeder::class,
            PlanSeeder::class,
        ]);
    }
}
