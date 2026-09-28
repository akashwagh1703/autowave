<?php

namespace App\Domain\Service\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Service\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Soft delete: past and upcoming appointments keep pointing at the service. It is removed from
 * every resource so it can no longer be booked.
 */
class DeleteService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $service->resources()->detach();
            $service->delete();

            $this->audit->log('service.deleted', $service, ['name' => $service->name]);
        });
    }
}
