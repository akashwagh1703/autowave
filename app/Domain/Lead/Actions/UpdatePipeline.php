<?php

namespace App\Domain\Lead\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-managed pipeline configuration (master prompt §24: business-specific stages are
 * configurable). The submitted list is the complete, ordered list:
 *
 * - Stages and sources are never deleted, only deactivated (leads keep pointing at them).
 * - At least one active stage per outcome, and at least one active source.
 * - A stage's outcome cannot change while leads are in it.
 */
class UpdatePipeline
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{id?: ?int, name: string, color: string, outcome: string, is_active: bool}>  $rows
     */
    public function stages(array $rows): void
    {
        $existing = LeadStage::query()->get()->keyBy('id');
        $this->ensureComplete($existing, $rows, 'stages');
        $this->ensureUniqueNames($rows, 'stages');

        foreach (StageOutcome::cases() as $outcome) {
            if (! collect($rows)->contains(fn (array $row) => $row['outcome'] === $outcome->value && $row['is_active'])) {
                throw ValidationException::withMessages(['stages' => "Keep at least one active \"{$outcome->label()}\" stage."]);
            }
        }

        $inUse = Lead::withTrashed()->distinct()->pluck('lead_stage_id')->flip();

        foreach ($rows as $index => $row) {
            $stage = isset($row['id']) ? $existing[$row['id']] : null;

            if ($stage && $stage->outcome->value !== $row['outcome'] && $inUse->has($stage->id)) {
                throw ValidationException::withMessages(["stages.{$index}.outcome" => "\"{$stage->name}\" has leads, so its outcome cannot change. Add a new stage instead."]);
            }
        }

        DB::transaction(function () use ($rows, $existing) {
            foreach (array_values($rows) as $index => $row) {
                $stage = isset($row['id']) ? $existing[$row['id']] : new LeadStage(['code' => $this->uniqueCode(LeadStage::class, $row['name'])]);

                $stage->fill([
                    'name' => $row['name'],
                    'color' => $row['color'],
                    'outcome' => $row['outcome'],
                    'is_active' => $row['is_active'],
                    'sort_order' => ($index + 1) * 10,
                ])->save();
            }

            $this->audit->log('crm.stages_updated', null, ['count' => count($rows)]);
        });
    }

    /**
     * @param  list<array{id?: ?int, name: string, is_active: bool}>  $rows
     */
    public function sources(array $rows): void
    {
        $existing = LeadSource::query()->get()->keyBy('id');
        $this->ensureComplete($existing, $rows, 'sources');
        $this->ensureUniqueNames($rows, 'sources');

        if (! collect($rows)->contains(fn (array $row) => $row['is_active'])) {
            throw ValidationException::withMessages(['sources' => 'Keep at least one active lead source.']);
        }

        DB::transaction(function () use ($rows, $existing) {
            foreach (array_values($rows) as $index => $row) {
                $source = isset($row['id']) ? $existing[$row['id']] : new LeadSource(['code' => $this->uniqueCode(LeadSource::class, $row['name'])]);

                $source->fill([
                    'name' => $row['name'],
                    'is_active' => $row['is_active'],
                    'sort_order' => ($index + 1) * 10,
                ])->save();
            }

            $this->audit->log('crm.sources_updated', null, ['count' => count($rows)]);
        });
    }

    /**
     * @param  Collection<int, Model>  $existing
     */
    private function ensureComplete(Collection $existing, array $rows, string $field): void
    {
        $submitted = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id);

        if ($submitted->diff($existing->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([$field => 'The list contains an unknown entry. Reload the page and try again.']);
        }

        if ($existing->keys()->diff($submitted)->isNotEmpty() || $submitted->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([$field => 'Entries cannot be removed, only deactivated. Reload the page and try again.']);
        }
    }

    private function ensureUniqueNames(array $rows, string $field): void
    {
        $duplicates = collect($rows)->map(fn (array $row) => Str::lower(trim($row['name'])))->duplicates();

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages([$field => 'Each name must be unique.']);
        }
    }

    /**
     * @param  class-string<LeadStage|LeadSource>  $model
     */
    private function uniqueCode(string $model, string $name): string
    {
        $base = Str::limit(Str::slug($name, '_') ?: 'item', 34, '');
        $code = $base;

        for ($i = 2; $model::query()->where('code', $code)->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        return $code;
    }
}
