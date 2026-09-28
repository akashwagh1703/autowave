<?php

namespace App\Domain\AI\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One AI provider call (succeeded or failed). */
#[Fillable([
    'tenant_id', 'user_id', 'feature', 'provider', 'model', 'status', 'prompt_tokens', 'completion_tokens',
    'total_tokens', 'cost', 'duration_ms', 'error',
])]
class AIUsage extends Model
{
    use BelongsToTenant;

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $table = 'ai_usage';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost' => 'decimal:6',
            'duration_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
