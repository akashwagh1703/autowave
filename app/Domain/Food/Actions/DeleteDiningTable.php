<?php

namespace App\Domain\Food\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Order;
use App\Domain\Food\Models\DiningTable;
use App\Domain\Food\Models\Reservation;
use Illuminate\Validation\ValidationException;

/** Soft-deletes a table. Refused while it has open orders or upcoming reservations. */
class DeleteDiningTable
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(DiningTable $table): void
    {
        if (Order::query()->open()->where('dining_table_id', $table->id)->exists()) {
            throw ValidationException::withMessages(['table' => __('This table has an open order. Close it first.')]);
        }

        if (Reservation::query()->holding()->where('dining_table_id', $table->id)->where('ends_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['table' => __('This table has upcoming reservations. Move or cancel them first.')]);
        }

        $table->delete();
        $this->audit->log('dining_table.deleted', $table, ['name' => $table->name]);
    }
}
