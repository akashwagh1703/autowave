<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in a conversation. Outbound rows take their delivery status from the outbound message. */
#[Fillable([
    'tenant_id', 'conversation_id', 'channel', 'direction', 'type', 'body', 'provider_message_id',
    'outbound_message_id', 'meta', 'sent_at',
])]
class ConversationMessage extends Model
{
    use BelongsToTenant;

    public const INBOUND = 'inbound';

    public const OUTBOUND = 'outbound';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function outbound(): BelongsTo
    {
        return $this->belongsTo(OutboundMessage::class, 'outbound_message_id');
    }
}
