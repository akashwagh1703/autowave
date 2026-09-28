<?php

namespace App\Domain\Education\Models;

use App\Domain\Education\Enums\DemoStatus;
use App\Domain\Lead\Models\Lead;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A free trial class for an enquiry (lead), optionally in a course or batch. */
#[Fillable(['tenant_id', 'lead_id', 'course_id', 'batch_id', 'scheduled_at', 'status', 'notes', 'created_by_user_id'])]
class DemoClass extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'scheduled',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'status' => DemoStatus::class,
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
