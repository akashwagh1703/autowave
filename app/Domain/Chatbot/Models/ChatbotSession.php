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

    /** In a booking, reservation or order, waiting for a typed answer (name, address, number of guests). */
    public const FLOW = 'flow';

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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $data = $this->data ?? [];

        if ($value === null) {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }

        $this->data = $data ?: null;
    }

    /**
     * Waits for a typed answer inside a flow; `then` is the option (without the prefix) to continue with.
     *
     * @param  list<string>  $then
     */
    public function await(string $flow, string $what, array $then = []): void
    {
        $this->state = self::FLOW;
        $this->put('flow', ['kind' => $flow, 'await' => $what, 'then' => $then]);
    }

    public function stopWaiting(): void
    {
        $this->state = self::MENU;
        $this->put('flow', null);
    }
}
