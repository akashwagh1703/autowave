<?php

namespace Database\Seeders;

use App\Domain\Tenant\Actions\CreateTenant;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Website\Actions\ProvisionWebsite;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the AutoWave Internal tenant (dogfooding), owned by the platform admin.
 */
class InternalTenantSeeder extends Seeder
{
    public function run(CreateTenant $createTenant, ProvisionWebsite $provisionWebsite): void
    {
        $config = config('autowave.internal_tenant');

        $existing = Tenant::query()->where('slug', $config['slug'])->first();

        if ($existing) {
            $provisionWebsite->ensureFor($existing);

            return;
        }

        $owner = User::query()->where('email', Str::lower(config('autowave.platform_admin.email')))->firstOrFail();

        $createTenant->handle($owner, $config['name'], $config['business_type'], [
            'slug' => $config['slug'],
            'is_internal' => true,
            'branding' => ['tagline' => 'Automation for local businesses'],
        ]);
    }
}
