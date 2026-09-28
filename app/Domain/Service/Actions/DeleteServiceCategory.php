<?php

namespace App\Domain\Service\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Service\Models\Service;
use App\Domain\Service\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;

/** Deletes a category; its services (including soft-deleted ones) become uncategorised. */
class DeleteServiceCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ServiceCategory $category): void
    {
        DB::transaction(function () use ($category) {
            Service::withTrashed()->where('service_category_id', $category->id)->update(['service_category_id' => null]);
            $category->delete();

            $this->audit->log('service_category.deleted', $category, ['name' => $category->name]);
        });
    }
}
