<?php

namespace App\Domain\Customer\Models;

use App\Domain\Activity\Models\Activity;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person the business serves. The customer timeline (activities) aggregates leads today and
 * bookings, orders and conversations in later phases.
 */
#[Fillable([
    'tenant_id', 'name', 'phone', 'phone_normalized', 'email', 'city', 'address', 'date_of_birth', 'birthday_greeted_on',
    'tags', 'notes', 'created_by_user_id',
])]
#[UseFactory(CustomerFactory::class)]
class Customer extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->isDirty('phone') || ! $customer->exists) {
                $customer->phone_normalized = Phone::normalize($customer->phone);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'date_of_birth' => 'date',
            'birthday_greeted_on' => 'date',
        ];
    }

    /** True when month/day match the tenant-local day (year ignored). */
    public function isBirthdayOn(CarbonInterface $day): bool
    {
        if (! $this->date_of_birth) {
            return false;
        }

        return (int) $this->date_of_birth->month === (int) $day->month
            && (int) $this->date_of_birth->day === (int) $day->day;
    }

    /** True when the birth month matches the tenant-local month. */
    public function isBirthdayMonth(CarbonInterface $day): bool
    {
        return $this->date_of_birth && (int) $this->date_of_birth->month === (int) $day->month;
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
