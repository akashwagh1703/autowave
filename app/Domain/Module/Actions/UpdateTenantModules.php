<?php

namespace App\Domain\Module\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Automation\Actions\ProvisionAutomations;
use App\Domain\Lead\Actions\ProvisionCrm;
use App\Domain\Module\Exceptions\CatalogItemUnavailable;
use App\Domain\Module\Exceptions\ModuleDependencyException;
use App\Domain\Module\Models\Module;
use App\Domain\Module\Services\ModuleManager;
use App\Domain\Service\Actions\ProvisionServiceCatalog;
use App\Domain\Tenant\Actions\BackfillTenantSettings;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Actions\ProvisionWebsite;
use App\Support\Enums\CatalogStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lets an owner change which catalogue modules are on after onboarding (Phase 6).
 * Engine-required modules stay on; dependencies are enforced; defaults are provisioned for newly enabled modules.
 */
class UpdateTenantModules
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly TenantContext $context,
        private readonly BackfillTenantSettings $backfillSettings,
        private readonly ProvisionWebsite $provisionWebsite,
        private readonly ProvisionCrm $provisionCrm,
        private readonly ProvisionServiceCatalog $provisionServiceCatalog,
        private readonly ProvisionAutomations $provisionAutomations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $requested
     * @return array{enabled: list<string>, enabled_now: list<string>, disabled_now: list<string>}
     */
    public function handle(Tenant $tenant, array $requested): array
    {
        $tenant->loadMissing(['businessType.engines', 'businessType.modules']);

        $active = Module::query()
            ->where('status', CatalogStatus::Active)
            ->pluck('code')
            ->all();

        $unknown = array_values(array_diff($requested, $active));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'modules' => __('Unknown or unavailable module(s): :codes.', ['codes' => implode(', ', $unknown)]),
            ]);
        }

        $required = $tenant->businessType?->engines
            ->flatMap->requiredModuleCodes()
            ->unique()
            ->values()
            ->all() ?? [];

        try {
            $desired = $this->modules->withDependencies(array_values(array_unique([...$requested, ...$required])));
        } catch (ModuleDependencyException|CatalogItemUnavailable $exception) {
            throw ValidationException::withMessages([
                'modules' => $exception->getMessage(),
            ]);
        }

        $current = $this->modules->enabledCodes($tenant);

        $toEnable = array_values(array_diff($desired, $current));
        $toDisable = array_values(array_diff($current, $desired));

        try {
            $result = DB::transaction(function () use ($tenant, $desired, $toEnable, $toDisable) {
                $enabledNow = $toEnable === []
                    ? []
                    : $this->modules->enableWithDependencies($tenant, $toEnable);

                $disabledNow = $this->disableMany($tenant, $toDisable);

                $this->rememberBackfill($tenant, $desired);

                return [
                    'enabled' => $this->modules->enabledCodes($tenant),
                    'enabled_now' => $enabledNow,
                    'disabled_now' => $disabledNow,
                ];
            });
        } catch (ModuleDependencyException|CatalogItemUnavailable $exception) {
            throw ValidationException::withMessages([
                'modules' => $exception->getMessage(),
            ]);
        }

        // Fresh module list for this request (sidebar / middleware memo).
        $this->context->set($tenant);

        if ($result['enabled_now'] !== []) {
            $this->provisionDefaults($tenant);
        }

        $this->audit->log('tenant.modules_updated', $tenant, [
            'enabled' => $result['enabled'],
            'enabled_now' => $result['enabled_now'],
            'disabled_now' => $result['disabled_now'],
        ]);

        return $result;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function disableMany(Tenant $tenant, array $codes): array
    {
        $remaining = array_values($codes);
        $disabled = [];

        while ($remaining !== []) {
            $progress = false;

            foreach ($remaining as $index => $code) {
                try {
                    $this->modules->disable($tenant, $code);
                    $disabled[] = $code;
                    unset($remaining[$index]);
                    $progress = true;
                } catch (ModuleDependencyException) {
                    // Another module in this batch still depends on it — try again later.
                }
            }

            if (! $progress) {
                // Force one failure message for the UI.
                $this->modules->disable($tenant, $remaining[array_key_first($remaining)]);
            }

            $remaining = array_values($remaining);
        }

        return $disabled;
    }

    /** @param  list<string>  $codes */
    private function rememberBackfill(Tenant $tenant, array $codes): void
    {
        $done = TenantSetting::withoutTenantScope()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', BackfillTenantModules::KEY)
            ->first()?->value ?? [];

        TenantSetting::withoutTenantScope()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'key' => BackfillTenantModules::KEY],
            ['value' => array_values(array_unique([...$done, ...$codes]))],
        );
    }

    private function provisionDefaults(Tenant $tenant): void
    {
        $this->backfillSettings->handle($tenant);
        $this->provisionWebsite->ensureFor($tenant);
        $this->provisionCrm->ensureFor($tenant);
        $this->provisionServiceCatalog->ensureFor($tenant);
        $this->provisionAutomations->ensureFor($tenant);
    }
}
