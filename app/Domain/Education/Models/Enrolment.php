<?php

namespace App\Domain\Education\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Education\Enums\EnrolmentStatus;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's admission to a batch (ADR-020). The student is a customer. The fee (fee_total minus
 * discount) is split into instalments; payments are allocated to them oldest first.
 */
#[Fillable([
    'tenant_id', 'customer_id', 'batch_id', 'lead_id', 'status', 'enrolled_on', 'fee_total', 'discount',
    'amount_paid', 'notes', 'completed_at', 'dropped_at', 'created_by_user_id',
])]
class Enrolment extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'active',
        'discount' => 0,
        'amount_paid' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrolmentStatus::class,
            'enrolled_on' => 'date',
            'fee_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'completed_at' => 'datetime',
            'dropped_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(FeeInstalment::class)->orderBy('sequence');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /** What the student owes in total (fee after discount). */
    public function netFee(): string
    {
        return bcsub((string) $this->fee_total, (string) $this->discount, 2);
    }

    public function balance(): string
    {
        return bcsub($this->netFee(), (string) $this->amount_paid, 2);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', EnrolmentStatus::Active);
    }
}
