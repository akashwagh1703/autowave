<?php

namespace App\Domain\Booking\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money received for an appointment (an advance or the full price), recorded by the team. No gateway yet. */
#[Fillable(['tenant_id', 'appointment_id', 'amount', 'method', 'reference', 'paid_at', 'recorded_by_user_id'])]
class AppointmentPayment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function methodLabel(): string
    {
        return config("commerce.payment_methods.{$this->method}", $this->method);
    }
}
