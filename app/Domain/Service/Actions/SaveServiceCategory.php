<?php

namespace App\Domain\Service\Actions;

use App\Domain\Service\Models\ServiceCategory;
use Illuminate\Validation\ValidationException;

/** Creates or renames a service category. Names are unique per tenant (case-insensitive). */
class SaveServiceCategory
{
    public const MAX_CATEGORIES = 50;

    public function handle(string $name, ?ServiceCategory $category = null): ServiceCategory
    {
        $taken = ServiceCategory::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($category, fn ($query) => $query->whereKeyNot($category->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('A category with this name already exists.')]);
        }

        if ($category) {
            $category->update(['name' => $name]);

            return $category;
        }

        if (ServiceCategory::query()->count() >= self::MAX_CATEGORIES) {
            throw ValidationException::withMessages(['name' => __('You can have up to :max categories.', ['max' => self::MAX_CATEGORIES])]);
        }

        return ServiceCategory::query()->create([
            'name' => $name,
            'sort_order' => (int) ServiceCategory::query()->max('sort_order') + 10,
        ]);
    }
}
