<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Files\Models\Attachment;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * One message in a conversation. Outbound rows take their delivery status from the outbound message.
 * A photo, video, voice note or document is an attachment (config('files.owners.conversation_message')).
 */
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

    /** Text shown for a message that is not plain text (previews, timeline, AI transcripts). */
    public const PLACEHOLDERS = [
        'image' => '[Image]',
        'video' => '[Video]',
        'audio' => '[Voice message]',
        'document' => '[Document]',
        'sticker' => '[Sticker]',
        'location' => '[Location]',
        'contacts' => '[Contact card]',
        'unsupported' => '[Unsupported message]',
    ];

    /** Media download states in meta.media.status (inbound files). */
    public const MEDIA_PENDING = 'pending';

    public const MEDIA_STORED = 'stored';

    public const MEDIA_SKIPPED = 'skipped';

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

    public function attachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable');
    }

    /** The text without its "[Image]"-style placeholder, for showing next to the file itself. */
    public function caption(): ?string
    {
        $placeholder = self::PLACEHOLDERS[$this->type] ?? null;
        $body = (string) $this->body;

        if ($placeholder !== null && str_starts_with($body, $placeholder)) {
            $body = trim(substr($body, strlen($placeholder)));
        }

        return $body === '' ? null : $body;
    }
}
