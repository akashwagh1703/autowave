<?php

namespace App\Domain\Food\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Food\Models\DiningTable;
use Illuminate\Validation\ValidationException;

/** Creates or updates a dining table. Names are unique per tenant (case-insensitive). */
class SaveDiningTable
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{name: string, seats: int, area?: ?string, is_active?: bool}  $data */
    public function handle(array $data, ?DiningTable $table = null): DiningTable
    {
        $name = trim($data['name']);

        $taken = DiningTable::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($table, fn ($query) => $query->whereKeyNot($table->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('A table with this name already exists.')]);
        }

        if (! $table && DiningTable::query()->count() >= (int) config('food.limits.tables')) {
            throw ValidationException::withMessages(['name' => __('You can have at most :max tables.', ['max' => config('food.limits.tables')])]);
        }

        $attributes = [
            'name' => $name,
            'seats' => max(1, min((int) $data['seats'], 100)),
            'area' => filled($data['area'] ?? null) ? trim($data['area']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        if ($table) {
            $table->update($attributes);
        } else {
            $table = DiningTable::query()->create([...$attributes, 'sort_order' => (int) DiningTable::query()->max('sort_order') + 10]);
        }

        $this->audit->log($table->wasRecentlyCreated ? 'dining_table.created' : 'dining_table.updated', $table, ['name' => $table->name]);

        return $table;
    }
}
