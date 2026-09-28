<?php

namespace App\Domain\Education\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One due date of an enrolment's fee plan. amount_paid is filled from payments, oldest instalment first. */
#[Fillable(['tenant_id', 'enrolment_id', 'sequence', 'due_on', 'amount', 'amount_paid', 'reminded_at', 'overdue_notified_at'])]
class FeeInstalment extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'amount_paid' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'due_on' => 'date',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'reminded_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    public function due(): string
    {
        return bcsub((string) $this->amount, (string) $this->amount_paid, 2);
    }

    public function isPaid(): bool
    {
        return bccomp($this->due(), '0', 2) <= 0;
    }

    /** Instalments with money still due. */
    public function scopeUnpaid(Builder $query): void
    {
        $query->whereColumn('amount_paid', '<', 'amount');
    }

    /** Unpaid instalments of active enrolments. */
    public function scopeOutstanding(Builder $query): void
    {
        $query->unpaid()->whereHas('enrolment', fn (Builder $enrolment) => $enrolment->active());
    }
}
