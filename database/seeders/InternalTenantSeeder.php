<?php

namespace Database\Seeders;

use App\Domain\Tenant\Actions\CreateTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the AutoWave Internal tenant (dogfooding), owned by the platform admin.
 */
class InternalTenantSeeder extends Seeder
{
    public function run(CreateTenant $createTenant): void
    {
        $config = config('autowave.internal_tenant');

        if (Tenant::query()->where('slug', $config['slug'])->exists()) {
            return;
        }

        $owner = User::query()->where('email', Str::lower(config('autowave.platform_admin.email')))->firstOrFail();

        $createTenant->handle($owner, $config['name'], $config['business_type'], [
            'slug' => $config['slug'],
            'is_internal' => true,
        ]);
    }
}
