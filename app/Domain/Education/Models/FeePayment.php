<?php

namespace App\Domain\Education\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fee money received for an enrolment, recorded by the team (cash, UPI, card…). No gateway yet. */
#[Fillable(['tenant_id', 'enrolment_id', 'amount', 'method', 'reference', 'paid_at', 'recorded_by_user_id'])]
class FeePayment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
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
