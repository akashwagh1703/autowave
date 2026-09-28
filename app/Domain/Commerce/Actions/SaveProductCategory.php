<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\ProductCategory;
use Illuminate\Validation\ValidationException;

/** Creates or renames a product category. Names are unique per tenant (case-insensitive). */
class SaveProductCategory
{
    public const MAX_CATEGORIES = 50;

    public function handle(string $name, ?ProductCategory $category = null): ProductCategory
    {
        $taken = ProductCategory::query()
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

        if (ProductCategory::query()->count() >= self::MAX_CATEGORIES) {
            throw ValidationException::withMessages(['name' => __('You can have up to :max categories.', ['max' => self::MAX_CATEGORIES])]);
        }

        return ProductCategory::query()->create([
            'name' => $name,
            'sort_order' => (int) ProductCategory::query()->max('sort_order') + 10,
        ]);
    }
}
