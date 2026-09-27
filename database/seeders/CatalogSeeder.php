<?php

namespace Database\Seeders;

use App\Domain\Business\Models\BusinessType;
use App\Domain\Engine\Models\Engine;
use App\Domain\Module\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Syncs config/catalog.php into the catalogue tables. Idempotent; safe in production.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;
        foreach (config('catalog.modules') as $code => $module) {
            Module::query()->updateOrCreate(['code' => $code], [
                'name' => $module['name'],
                'description' => $module['description'] ?? null,
                'type' => $module['type'] ?? 'core',
                'version' => $module['version'] ?? '1.0',
                'status' => $module['status'] ?? 'active',
                'sort_order' => $sort += 10,
            ]);
        }

        $modules = Module::query()->pluck('id', 'code');

        foreach (config('catalog.modules') as $code => $module) {
            Module::query()->find($modules[$code])->dependencies()->sync(
                collect($module['depends_on'] ?? [])->map(fn (string $dependency) => $modules[$dependency])->all()
            );
        }

        $sort = 0;
        foreach (config('catalog.engines') as $code => $engine) {
            Engine::query()->updateOrCreate(['code' => $code], [
                'name' => $engine['name'],
                'description' => $engine['description'] ?? null,
                'status' => $engine['status'] ?? 'active',
                'configuration' => ['requires_modules' => $engine['requires_modules'] ?? []],
                'sort_order' => $sort += 10,
            ]);
        }

        $engines = Engine::query()->pluck('id', 'code');

        $sort = 0;
        foreach (config('catalog.business_types') as $code => $type) {
            $businessType = BusinessType::query()->updateOrCreate(
                ['code' => $code, 'version' => $type['version']],
                [
                    'name' => $type['name'],
                    'description' => $type['description'] ?? null,
                    'status' => $type['status'] ?? 'active',
                    'is_public' => $type['public'] ?? true,
                    'configuration' => $type['configuration'] ?? [],
                    'sort_order' => $sort += 10,
                ],
            );

            $businessType->engines()->sync(collect($type['engines'])->map(fn (string $engine) => $engines[$engine])->all());
            $businessType->modules()->sync(collect($type['modules'])->mapWithKeys(fn (string $module) => [
                $modules[$module] => ['enabled' => true],
            ])->all());
        }
    }
}
