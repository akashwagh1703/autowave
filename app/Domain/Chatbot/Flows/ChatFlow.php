<?php

namespace App\Domain\Chatbot\Flows;

use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotContent;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Support\Interactive;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A booking, reservation or order taken step by step in a WhatsApp chat (ADR-021, step 2). Each option id
 * carries what was chosen so far (`aw.bk.at.{service}.{resource}.{timestamp}`), so an older button still
 * works; only typed answers and the cart live in the session. The final step calls the same online service
 * as the website, with the source `whatsapp`.
 */
abstract class ChatFlow
{
    public const PREFIX = 'aw.';

    private const PARTS = ['m' => 'Morning', 'a' => 'Afternoon', 'e' => 'Evening'];

    public function __construct(protected readonly ChatbotContent $content) {}

    /** The option key after the prefix, e.g. `bk`. */
    abstract public function key(): string;

    /**
     * @param  list<string>  $args  the option id parts after the key
     * @return array{body: string, interactive: ?array<string, mixed>}|null null when the option is unknown
     */
    abstract public function handle(ChatbotSession $session, Conversation $conversation, array $args): ?array;

    /**
     * A typed answer while the flow waits for one (`$what`). The name is handled here.
     *
     * @return array{body: string, interactive: ?array<string, mixed>}
     */
    public function typed(ChatbotSession $session, Conversation $conversation, string $what, string $text): array
    {
        if ($what !== 'name') {
            return $this->reply(__('Sorry, I did not get that.'), $this->menuButtons());
        }

        $name = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || preg_match('/\p{L}/u', $name) !== 1) {
            return $this->reply(__('Please type your name, for example *Priya Sharma*.'), null);
        }

        $then = $session->get('flow')['then'] ?? [];
        $session->stopWaiting();
        $session->put('name', Str::title($name));

        return $this->handle($session, $conversation, array_slice($then, 1)) ?? $this->reply(__('Thank you!'), $this->menuButtons());
    }

    /**
     * @param  array<string, mixed>|null  $interactive
     * @return array{body: string, interactive: ?array<string, mixed>}
     */
    protected function reply(string $body, ?array $interactive): array
    {
        return ['body' => Str::limit($body, Interactive::BODY_MAX - 4), 'interactive' => $interactive];
    }

    protected function id(string|int ...$parts): string
    {
        return self::PREFIX.$this->key().'.'.implode('.', $parts);
    }

    /**
     * One page of list rows; when more remain, the last row opens the next page.
     *
     * @param  list<array{id: string, title: string, description?: ?string}>  $rows
     * @param  callable(int): string  $pageId
     * @return list<array{id: string, title: string, description?: ?string}>
     */
    protected function page(array $rows, int $page, callable $pageId, string $more): array
    {
        $size = Interactive::MAX_ROWS - 1;
        $page = max(0, $page);

        if (count($rows) <= Interactive::MAX_ROWS && $page === 0) {
            return $rows;
        }

        $slice = array_slice($rows, $page * $size, $size);

        if (count($rows) > ($page + 1) * $size) {
            $slice[] = ['id' => $pageId($page + 1), 'title' => $more];
        }

        return $slice;
    }

    /**
     * Free times on a day as list rows: straight away when there are few, else grouped by part of the day.
     *
     * @param  list<array{starts_at: string, time: string, description?: ?string}>  $slots
     * @param  callable(int): string  $atId  option for a start timestamp
     * @param  callable(string, int): string  $partId  option for a part of the day and page
     * @return list<array{id: string, title: string, description?: ?string}>
     */
    protected function timeRows(array $slots, ?string $part, int $page, callable $atId, callable $partId): array
    {
        if ($part === null && count($slots) > Interactive::MAX_ROWS) {
            $rows = [];

            foreach (self::PARTS as $code => $label) {
                $inPart = array_values(array_filter($slots, fn (array $slot) => self::partOf($slot['time']) === $code));

                if ($inPart !== []) {
                    $rows[] = [
                        'id' => $partId($code, 0),
                        'title' => __($label),
                        'description' => trans_choice(':count time from :time|:count times from :time', count($inPart), ['time' => $this->time($inPart[0]['starts_at'])]),
                    ];
                }
            }

            return $rows;
        }

        $chosen = $part === null ? $slots : array_values(array_filter($slots, fn (array $slot) => self::partOf($slot['time']) === $part));
        $rows = array_map(fn (array $slot) => array_filter([
            'id' => $atId(CarbonImmutable::parse($slot['starts_at'])->getTimestamp()),
            'title' => $this->time($slot['starts_at']),
            'description' => $slot['description'] ?? null,
        ], fn ($value) => $value !== null), $chosen);

        return $this->page($rows, $page, fn (int $next) => $partId($part ?? 'all', $next), __('Later times'));
    }

    protected static function partOf(string $time): string
    {
        $hour = (int) substr($time, 0, 2);

        return match (true) {
            $hour < 12 => 'm',
            $hour < 17 => 'a',
            default => 'e',
        };
    }

    protected function local(CarbonImmutable|string|int $at): CarbonImmutable
    {
        $instant = is_int($at) ? CarbonImmutable::createFromTimestamp($at) : CarbonImmutable::parse($at);

        return $instant->setTimezone(TenantTime::timezone());
    }

    protected function time(CarbonImmutable|string|int $at): string
    {
        return $this->local($at)->format('g:i a');
    }

    /** "Today", "Tomorrow" or "Wed 8 Oct". */
    protected function day(CarbonImmutable|string $date): string
    {
        $day = is_string($date) ? CarbonImmutable::createFromFormat('!Y-m-d', $date, TenantTime::timezone()) : $date->setTimezone(TenantTime::timezone())->startOfDay();
        $today = CarbonImmutable::now(TenantTime::timezone())->startOfDay();

        return match ((int) $today->diffInDays($day, false)) {
            0 => __('Today'),
            1 => __('Tomorrow'),
            default => $day->format('D j M'),
        };
    }

    protected function when(CarbonImmutable|string|int $at): string
    {
        $local = $this->local($at);

        return $this->day($local).', '.$local->format('g:i a');
    }

    /** `Ymd` in an option id to a local date `Y-m-d`; null when invalid. */
    protected static function date(?string $ymd): ?string
    {
        $date = $ymd !== null && preg_match('/^\d{8}$/', $ymd) === 1 ? CarbonImmutable::createFromFormat('!Ymd', $ymd) : null;

        return $date && $date->format('Ymd') === $ymd ? $date->format('Y-m-d') : null;
    }

    protected static function ymd(string $date): string
    {
        return str_replace('-', '', $date);
    }

    protected static function int(?string $value): int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : 0;
    }

    /** The name to book under: typed earlier, the customer's, the lead's, or the WhatsApp profile name. */
    protected function name(ChatbotSession $session, Conversation $conversation): ?string
    {
        $candidates = [
            $session->get('name'),
            $conversation->customer?->name,
            $conversation->lead?->name,
            $conversation->contact_name,
        ];

        foreach ($candidates as $name) {
            $name = trim((string) $name);

            if (mb_strlen($name) >= 2 && preg_match('/\p{L}/u', $name) === 1 && ! str_starts_with($name, 'WhatsApp user')) {
                return Str::limit($name, 120, '');
            }
        }

        return null;
    }

    /**
     * Asks for the name, then continues with `$then` (option id parts after the key).
     *
     * @param  list<string|int>  $then
     * @return array{body: string, interactive: ?array<string, mixed>}
     */
    protected function askName(ChatbotSession $session, array $then): array
    {
        $session->await($this->key(), 'name', array_map('strval', [$this->key(), ...$then]));

        return $this->reply(__('Almost done! What name should we put this under? Please type your name.'), null);
    }

    /** The customer's phone: the WhatsApp number itself. */
    protected function phone(Conversation $conversation): string
    {
        return (string) $conversation->contact_handle;
    }

    protected function email(Conversation $conversation): ?string
    {
        return $conversation->customer?->email ?? $conversation->lead?->email;
    }

    /** Remembers the customer on the chat, so the inbox shows who it is. */
    protected function linkCustomer(Conversation $conversation, ?int $customerId): void
    {
        if ($customerId === null || $conversation->customer_id !== null) {
            return;
        }

        try {
            $conversation->forceFill(['customer_id' => $customerId])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** True when this confirmation was already handled (a double tap), then remembers it. */
    protected function alreadyDone(ChatbotSession $session, string $key): bool
    {
        $done = $session->get('done', []);

        if (in_array($key, $done, true)) {
            return true;
        }

        $session->put('done', array_slice([...$done, $key], -5));

        return false;
    }

    protected static function firstError(ValidationException $exception): string
    {
        return (string) collect($exception->errors())->flatten()->first();
    }

    /** @return array{body: string, interactive: ?array<string, mixed>} */
    protected function failed(string $message, ?string $askTopic = null): array
    {
        return $this->reply($message, Interactive::buttons(array_values(array_filter([
            $askTopic ? ['id' => self::PREFIX.'ask.'.$askTopic, 'title' => __('Ask us')] : null,
            ['id' => self::PREFIX.'human', 'title' => __('Talk to us')],
            ['id' => self::PREFIX.'menu', 'title' => __('Main menu')],
        ]))));
    }

    /** @return array{body: string, interactive: ?array<string, mixed>} */
    protected function cancelled(string $message): array
    {
        return $this->reply($message, $this->menuButtons());
    }

    /** @return array<string, mixed> */
    protected function menuButtons(): array
    {
        return Interactive::buttons([
            ['id' => self::PREFIX.'menu', 'title' => __('Main menu')],
            ['id' => self::PREFIX.'human', 'title' => __('Talk to us')],
        ]);
    }

    /** A line pointing to the same page on the website, when it is live. */
    protected function websiteLine(string $anchor, string $text): string
    {
        $website = $this->content->website();

        return $website ? "\n\n".$text.' '.$website.'/'.$anchor : '';
    }
}
