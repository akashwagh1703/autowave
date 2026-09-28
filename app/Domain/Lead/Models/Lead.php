<?php

namespace App\Domain\Lead\Models;

use App\Domain\Activity\Models\Activity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\Phone;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

#[Fillable([
    'tenant_id', 'lead_stage_id', 'lead_source_id', 'customer_id', 'assigned_tenant_user_id',
    'name', 'phone', 'phone_normalized', 'email', 'interest', 'estimated_value',
    'next_followup_at', 'last_contacted_at', 'converted_at', 'lost_at', 'lost_reason', 'created_by_user_id',
])]
#[UseFactory(LeadFactory::class)]
class Lead extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Lead $lead): void {
            if ($lead->isDirty('phone') || ! $lead->exists) {
                $lead->phone_normalized = Phone::normalize($lead->phone);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'next_followup_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'converted_at' => 'datetime',
            'lost_at' => 'datetime',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(LeadStage::class, 'lead_stage_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assigned_tenant_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function isOpen(): bool
    {
        return $this->stage?->outcome === StageOutcome::Open;
    }

    /** Leads whose current stage has an open outcome. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereHas('stage', fn (Builder $stage) => $stage->where('outcome', StageOutcome::Open));
    }

    /** Open leads with a follow-up due before the given moment (default: end of today in tenant time). */
    public function scopeFollowUpDue(Builder $query, ?\DateTimeInterface $before = null): void
    {
        $before ??= now(app(TenantContext::class)->get()?->timezone ?? config('app.timezone'))->endOfDay();

        // Timestamps are stored in UTC; compare in UTC regardless of the given zone.
        $query->open()->whereNotNull('next_followup_at')->where('next_followup_at', '<=', Carbon::instance($before)->utc());
    }
}
