<?php

namespace App\Domain\Loyalty\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id', 'customer_id', 'public_token', 'stamps_balance', 'lifetime_stamps', 'rewards_redeemed', 'joined_at',
])]
class LoyaltyAccount extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'stamps_balance' => 'integer',
            'lifetime_stamps' => 'integer',
            'rewards_redeemed' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function stamps(): HasMany
    {
        return $this->hasMany(LoyaltyStamp::class)->orderByDesc('id');
    }

    public function rewardsAvailable(int $required): int
    {
        return $required > 0 ? intdiv($this->stamps_balance, $required) : 0;
    }
}
