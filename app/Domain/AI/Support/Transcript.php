<?php

namespace App\Domain\AI\Support;

use App\Domain\Activity\Models\Activity;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Bounded plain-text views of a conversation or a timeline for prompts (config/ai.php `context`).
 * Bodies are truncated; contact details other than the display name are left out.
 */
class Transcript
{
    /** @return Collection<int, ConversationMessage> the latest messages, oldest first */
    public function messages(Conversation $conversation): Collection
    {
        return $conversation->messages()
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->limit((int) config('ai.context.messages'))
            ->get()
            ->reverse()
            ->values();
    }

    public function conversation(Conversation $conversation): string
    {
        return $this->messages($conversation)
            ->map(fn (ConversationMessage $message) => sprintf(
                '%s (%s): %s',
                $message->direction === ConversationMessage::INBOUND ? 'Customer' : 'Business',
                $this->time($message->sent_at),
                $this->body($message->body, $message->type),
            ))
            ->implode("\n");
    }

    /**
     * @param  iterable<Activity>  $activities  newest first
     */
    public function timeline(iterable $activities): string
    {
        $lines = [];

        foreach ($activities as $activity) {
            $lines[] = sprintf('%s · %s%s', $this->time($activity->occurred_at), $this->label($activity), filled($activity->body) ? ': '.$this->body($activity->body) : '');
        }

        return implode("\n", array_reverse($lines));
    }

    private function label(Activity $activity): string
    {
        $meta = $activity->metadata ?? [];
        $inbound = ($meta['direction'] ?? null) === 'inbound';

        return match (true) {
            in_array($activity->type, ['whatsapp', 'instagram', 'email'], true) => Str::headline($activity->type).($inbound ? ' from the contact' : ' to the contact'),
            $activity->type === 'website_enquiry' => 'Website enquiry',
            $activity->type === 'updated' => 'Details updated ('.implode(', ', (array) ($meta['changed'] ?? [])).')',
            default => Str::headline($activity->type),
        };
    }

    private function body(?string $body, string $type = 'text'): string
    {
        if (! filled($body)) {
            return $type === 'text' ? '(empty)' : "({$type})";
        }

        return Str::limit(preg_replace('/\s+/', ' ', trim($body)) ?? '', (int) config('ai.context.message_chars'));
    }

    private function time(?\DateTimeInterface $at): string
    {
        return $at ? Carbon::instance($at)->setTimezone(TenantTime::timezone())->format('j M, g:i A') : '';
    }
}
