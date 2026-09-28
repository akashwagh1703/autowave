<?php

namespace App\Domain\Commerce\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use Illuminate\Support\Facades\DB;

/** Deletes a category; its products (including deleted ones) become uncategorised. */
class DeleteProductCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ProductCategory $category): void
    {
        DB::transaction(function () use ($category) {
            Product::withTrashed()->where('product_category_id', $category->id)->update(['product_category_id' => null]);
            $category->delete();

            $this->audit->log('product_category.deleted', $category, ['name' => $category->name]);
        });
    }
}
