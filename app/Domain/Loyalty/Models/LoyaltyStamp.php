<?php

namespace App\Domain\Loyalty\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'loyalty_account_id', 'delta', 'source', 'source_id', 'note', 'created_by_user_id',
])]
class LoyaltyStamp extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'source_id' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
