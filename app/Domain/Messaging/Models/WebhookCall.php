<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A verified webhook body from Meta, waiting for or done with processing. Pruned after the retention period. */
#[Fillable(['tenant_id', 'messaging_channel_id', 'payload', 'status', 'attempts', 'error', 'processed_at'])]
class WebhookCall extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    protected $table = 'messaging_webhook_calls';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(MessagingChannel::class, 'messaging_channel_id');
    }
}
