<?php

namespace App\Domain\Customer\Models;

use App\Domain\Activity\Models\Activity;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use App\Support\Phone;
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
#[Fillable(['tenant_id', 'name', 'phone', 'phone_normalized', 'email', 'city', 'address', 'tags', 'notes', 'created_by_user_id'])]
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
        ];
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
