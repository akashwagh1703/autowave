<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Model events stay enabled: tenant guards and the domain cache rely on them.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlatformSeeder::class,
            PlatformAdminSeeder::class,
            InternalTenantSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoTenantSeeder::class);
        }

        $this->call(TenantBackfillSeeder::class);

        if (app()->environment('local')) {
            $this->call([DemoAutomationSeeder::class, DemoCrmSeeder::class, DemoBookingSeeder::class, DemoWebsiteSeeder::class]);
        }
    }
}
