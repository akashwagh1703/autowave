<?php

namespace App\Domain\AI\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stored AI output for a record: a summary, a reply draft or lead-detail suggestions.
 * `key` identifies the input (for example the last message id), so an unchanged record reuses it.
 */
#[Fillable(['tenant_id', 'feature', 'subject_type', 'subject_id', 'key', 'status', 'output', 'created_by_user_id'])]
class AIResult extends Model
{
    use BelongsToTenant;

    public const READY = 'ready';

    public const USED = 'used';

    public const DISMISSED = 'dismissed';

    protected $table = 'ai_results';

    protected $attributes = [
        'status' => self::READY,
    ];

    protected function casts(): array
    {
        return [
            'output' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeFor(Builder $query, string $feature, string $subjectType, int $subjectId): void
    {
        $query->where('feature', $feature)->where('subject_type', $subjectType)->where('subject_id', $subjectId);
    }
}
