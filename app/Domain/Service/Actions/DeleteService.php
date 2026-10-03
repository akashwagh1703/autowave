<?php

namespace App\Domain\Service\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Files\Actions\ManageAttachments;
use App\Domain\Service\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Soft delete: past and upcoming appointments keep pointing at the service. It is removed from
 * every resource so it can no longer be booked. Its video and brochures are deleted.
 */
class DeleteService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ManageAttachments $attachments,
    ) {}

    public function handle(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $service->resources()->detach();
            $service->delete();

            $this->audit->log('service.deleted', $service, ['name' => $service->name]);
        });

        $this->attachments->deleteAllFor($service);
    }
}
