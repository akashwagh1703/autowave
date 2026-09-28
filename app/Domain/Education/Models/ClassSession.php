<?php

namespace App\Domain\Education\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One class of a batch on a local date; created when attendance is taken. */
#[Fillable(['tenant_id', 'batch_id', 'held_on', 'topic', 'created_by_user_id'])]
class ClassSession extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'held_on' => 'date',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class)->withTrashed();
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
