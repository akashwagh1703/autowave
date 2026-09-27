<?php

namespace App\Domain\Tenant\Actions;

use App\Domain\Business\Models\BusinessType;
use App\Domain\Domain\Actions\AssignDefaultDomain;
use App\Domain\Engine\Services\EngineManager;
use App\Domain\Module\Exceptions\CatalogItemUnavailable;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\RBAC\Actions\AssignRole;
use App\Domain\RBAC\Actions\ProvisionTenantRoles;
use App\Domain\Tenant\Enums\MembershipStatus;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Events\TenantCreated;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Tenant\Support\TenantSlug;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates a fully provisioned tenant from a business type preset (ADR-005):
 * owner membership, roles, modules, engines, default settings and subdomain.
 * All or nothing; TenantCreated fires after commit.
 */
class CreateTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ProvisionTenantRoles $provisionRoles,
        private readonly AssignRole $assignRole,
        private readonly ModuleManager $modules,
        private readonly EngineManager $engines,
        private readonly AssignDefaultDomain $assignDomain,
    ) {}

    /**
     * @param  array{slug?: string, is_internal?: bool, timezone?: string, locale?: string, currency?: string}  $options
     */
    public function handle(User $owner, string $name, string|BusinessType $businessType, array $options = []): Tenant
    {
        $type = $businessType instanceof BusinessType ? $businessType : BusinessType::latestActive($businessType);

        if (! $type) {
            throw new InvalidArgumentException("Business type [{$businessType}] does not exist or is not active.");
        }

        $slug = $options['slug'] ?? TenantSlug::generate($name);

        if (! TenantSlug::isAvailable($slug)) {
            throw new InvalidArgumentException("Tenant slug [{$slug}] is invalid or already taken.");
        }

        $tenant = DB::transaction(function () use ($owner, $name, $type, $slug, $options) {
            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $slug,
                'business_type_id' => $type->getKey(),
                'business_type_version' => $type->version,
                'status' => TenantStatus::Active,
                'is_internal' => $options['is_internal'] ?? false,
                'timezone' => $options['timezone'] ?? 'Asia/Kolkata',
                'locale' => $options['locale'] ?? 'en',
                'currency' => $options['currency'] ?? 'INR',
            ]);

            $membership = TenantUser::query()->create([
                'tenant_id' => $tenant->getKey(),
                'user_id' => $owner->getKey(),
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            $this->context->run($tenant, function (Tenant $tenant) use ($membership, $type) {
                $roles = $this->provisionRoles->handle($tenant);
                $this->assignRole->handle($membership, $roles->get('owner') ?? throw new InvalidArgumentException('The owner template role is missing; run RbacSeeder.'));

                $this->enableFeatures($tenant, $type);
                $this->writeDefaultSettings($tenant, $type);
                $this->assignDomain->handle($tenant);
            });

            return $tenant;
        });

        TenantCreated::dispatch($tenant, $owner);

        return $tenant;
    }

    private function enableFeatures(Tenant $tenant, BusinessType $type): void
    {
        $type->loadMissing(['engines', 'modules']);

        $moduleCodes = $type->modules->filter(fn ($module) => $module->pivot->enabled)->pluck('code');
        $engineModuleCodes = $type->engines->flatMap->requiredModuleCodes();

        $this->modules->enableWithDependencies($tenant, $moduleCodes->merge($engineModuleCodes)->unique()->values()->all());

        foreach ($type->engines as $engine) {
            if (! $engine->isActive()) {
                throw CatalogItemUnavailable::engine($engine->code);
            }

            $this->engines->enable($tenant, $engine->code);
        }
    }

    private function writeDefaultSettings(Tenant $tenant, BusinessType $type): void
    {
        $settings = [
            'branding' => [
                'business_name' => $tenant->name,
                'primary_color' => '#4f46e5',
                'logo_path' => null,
            ],
            ...($type->configuration ?? []),
        ];

        foreach ($settings as $key => $value) {
            TenantSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
