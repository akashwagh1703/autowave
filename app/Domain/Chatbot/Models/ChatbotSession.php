<?php

namespace App\Domain\Chatbot\Models;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one conversation is in the WhatsApp assistant (ADR-021): the state (`menu` or `question`), what
 * the contact chose (`data`), how many messages in a row were not understood, and until when the
 * assistant stays quiet because a person is handling the chat.
 */
#[Fillable(['tenant_id', 'conversation_id', 'state', 'data', 'misses', 'last_reply_at', 'paused_until'])]
class ChatbotSession extends Model
{
    use BelongsToTenant;

    public const MENU = 'menu';

    /** Waiting for the contact to type a question or request for the team. */
    public const QUESTION = 'question';

    protected $attributes = ['state' => self::MENU, 'misses' => 0];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'misses' => 'integer',
            'last_reply_at' => 'datetime',
            'paused_until' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isPaused(): bool
    {
        return (bool) $this->paused_until?->isFuture();
    }

    /** No reply for a while: the next message starts again with the welcome. */
    public function isStale(): bool
    {
        return ! $this->last_reply_at || $this->last_reply_at->lt(now()->subMinutes((int) config('chatbot.session_minutes')));
    }
}
