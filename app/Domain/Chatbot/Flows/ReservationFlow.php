<?php

namespace App\Domain\Chatbot\Flows;

use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotContent;
use App\Domain\Food\Enums\ReservationStatus;
use App\Domain\Food\Support\FoodSettings;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Support\Interactive;
use App\Domain\Website\Services\OnlineReservations;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reserving a table in the chat: day → time → number of guests → confirm. Same rules as the website's
 * reservation form (OnlineReservations: opening times, notice, largest group, auto-confirm); the team
 * assigns the table.
 *
 * Options: `aw.rv.days.{offset}`, `aw.rv.day.{Ymd}`, `aw.rv.part.{Ymd}.{part}.{page}`, `aw.rv.at.{timestamp}`,
 * `aw.rv.ppl.{timestamp}.{guests}`, `aw.rv.big.{timestamp}` (type the number), `aw.rv.ok.{timestamp}.{guests}`,
 * `aw.rv.no`.
 */
class ReservationFlow extends ChatFlow
{
    public function __construct(
        ChatbotContent $content,
        private readonly OnlineReservations $reservations,
        private readonly FoodSettings $settings,
    ) {
        parent::__construct($content);
    }

    public function key(): string
    {
        return 'rv';
    }

    /** @return array{body: string, interactive: ?array<string, mixed>} */
    public function start(): array
    {
        return $this->days(0);
    }

    public function handle(ChatbotSession $session, Conversation $conversation, array $args): ?array
    {
        $timestamp = self::int($args[1] ?? null);

        return match ($args[0] ?? null) {
            'days' => $this->days(self::int($args[1] ?? null)),
            'day' => $this->times(self::date($args[1] ?? null), null, 0),
            'part' => $this->times(self::date($args[1] ?? null), in_array($args[2] ?? null, ['m', 'a', 'e'], true) ? $args[2] : null, self::int($args[3] ?? null)),
            'at' => $this->guests($timestamp),
            'big' => $this->askGuests($session, $timestamp),
            'ppl' => $this->confirm($session, $conversation, $timestamp, self::int($args[2] ?? null)),
            'ok' => $this->reserve($session, $conversation, $timestamp, self::int($args[2] ?? null)),
            'no' => $this->cancelled(__('No problem, nothing was reserved.')),
            default => null,
        };
    }

    public function typed(ChatbotSession $session, Conversation $conversation, string $what, string $text): array
    {
        if ($what !== 'guests') {
            return parent::typed($session, $conversation, $what, $text);
        }

        $guests = preg_match('/\d+/', $text, $match) === 1 ? (int) $match[0] : 0;
        $timestamp = self::int($session->get('flow')['then'][2] ?? null);

        if ($guests < 1) {
            return $this->reply(__('Please type the number of guests, for example *12*.'), null);
        }

        $session->stopWaiting();

        return $this->confirm($session, $conversation, $timestamp, $guests);
    }

    private function days(int $offset): array
    {
        $props = $this->reservations->props();
        $timezone = TenantTime::timezone();
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $props['first_date'], $timezone);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $props['last_date'], $timezone);
        $rows = [];
        $next = null;

        for ($cursor = $first->addDays($offset); $cursor <= $last; $cursor = $cursor->addDay()) {
            $slots = $this->reservations->slots($cursor->toDateString());

            if ($slots === []) {
                continue;
            }

            if (count($rows) === Interactive::MAX_ROWS - 1) {
                $next = (int) $first->diffInDays($cursor);
                break;
            }

            $rows[] = [
                'id' => $this->id('day', self::ymd($cursor->toDateString())),
                'title' => $this->day($cursor),
                'description' => __('From :time', ['time' => $this->time($slots[0]['starts_at'])]),
            ];
        }

        if ($rows === []) {
            return $this->failed(__('Sorry, there are no tables to reserve online in the next few days.'), 'reserve');
        }

        if ($next !== null) {
            $rows[] = ['id' => $this->id('days', $next), 'title' => __('Later dates')];
        }

        return $this->reply(
            __('Which day would you like to come?').$this->websiteLine('#reservation', __('You can also reserve on our website:')),
            Interactive::list(__('Choose a day'), $rows, __('Reserve a table')),
        );
    }

    private function times(?string $date, ?string $part, int $page): array
    {
        $slots = $date ? $this->reservations->slots($date) : [];

        if ($slots === []) {
            return $this->days(0);
        }

        $rows = $this->timeRows(
            $slots,
            $part,
            $page,
            fn (int $timestamp) => $this->id('at', $timestamp),
            fn (string $code, int $next) => $this->id('part', self::ymd($date), $code, $next),
        );

        return $this->reply(__('What time on :day?', ['day' => $this->day($date)]), Interactive::list(__('Choose a time'), $rows, __('Reserve a table')));
    }

    private function guests(int $timestamp): array
    {
        if (! $this->bookable($timestamp)) {
            return $this->days(0);
        }

        $max = (int) $this->settings->reservations()['max_party_size'];
        $shown = min($max, Interactive::MAX_ROWS);
        $rows = [];

        for ($guests = 1; $guests <= $shown; $guests++) {
            $rows[] = ['id' => $this->id('ppl', $timestamp, $guests), 'title' => trans_choice(':count guest|:count guests', $guests)];
        }

        if ($max > Interactive::MAX_ROWS) {
            $rows[Interactive::MAX_ROWS - 1] = ['id' => $this->id('big', $timestamp), 'title' => __(':count or more', ['count' => Interactive::MAX_ROWS])];
        }

        return $this->reply(__('How many people, including you?'), Interactive::list(__('Choose'), $rows, __('Reserve a table')));
    }

    private function askGuests(ChatbotSession $session, int $timestamp): array
    {
        $session->await($this->key(), 'guests', [$this->key(), 'ppl', (string) $timestamp]);

        return $this->reply(__('Please type the number of guests.'), null);
    }

    private function confirm(ChatbotSession $session, Conversation $conversation, int $timestamp, int $guests): array
    {
        if (! $this->bookable($timestamp)) {
            $days = $this->days(0);

            return [...$days, 'body' => __('Sorry, that time is no longer available.')."\n\n".$days['body']];
        }

        $max = (int) $this->settings->reservations()['max_party_size'];

        if ($guests < 1 || $guests > $max) {
            return $this->failed(__('For groups larger than :max, please talk to us and we will arrange it.', ['max' => $max]), 'reserve');
        }

        $name = $this->name($session, $conversation);

        if ($name === null) {
            return $this->askName($session, ['ppl', $timestamp, $guests]);
        }

        $lines = array_filter([
            __('*Please check your reservation*'),
            '',
            '📅 '.$this->when($timestamp),
            '👥 '.trans_choice(':count guest|:count guests', $guests),
            '🙍 '.$name,
            '',
            $this->settings->reservations()['auto_confirm'] ? null : __('We will confirm it here shortly.'),
        ], fn ($line) => $line !== null);

        return $this->reply(trim(implode("\n", $lines)), Interactive::buttons([
            ['id' => $this->id('ok', $timestamp, $guests), 'title' => __('Confirm')],
            ['id' => $this->id('day', self::ymd($this->local($timestamp)->toDateString())), 'title' => __('Change time')],
            ['id' => $this->id('no'), 'title' => __('Cancel')],
        ]));
    }

    private function reserve(ChatbotSession $session, Conversation $conversation, int $timestamp, int $guests): array
    {
        $name = $this->name($session, $conversation);

        if (! $timestamp || $name === null) {
            return $this->confirm($session, $conversation, $timestamp, $guests);
        }

        if ($this->alreadyDone($session, "rv.{$timestamp}.{$guests}")) {
            return $this->reply(__('Your table for :when is already requested. 👍', ['when' => $this->when($timestamp)]), $this->menuButtons());
        }

        try {
            $reservation = $this->reservations->book([
                'name' => $name,
                'phone' => $this->phone($conversation),
                'email' => $this->email($conversation),
                'party_size' => $guests,
                'starts_at' => CarbonImmutable::createFromTimestamp($timestamp)->toIso8601String(),
            ], 'whatsapp');
        } catch (ValidationException $exception) {
            $session->put('done', array_values(array_diff($session->get('done', []), ["rv.{$timestamp}.{$guests}"])) ?: null);

            return $this->failed(self::firstError($exception), 'reserve');
        }

        $this->linkCustomer($conversation, $reservation->customer_id);
        $details = __(':guests on :when', ['guests' => trans_choice(':count guest|:count guests', $guests), 'when' => $this->when($timestamp)]);

        return $this->reply($reservation->status === ReservationStatus::Confirmed
            ? __('✅ Your table is reserved: :details. See you then!', ['details' => $details])
            : __('🙏 Thank you, :name! We have your table request for :details. We will confirm it here shortly.', ['name' => $name, 'details' => $details]), Interactive::buttons([
                ['id' => self::PREFIX.'menu', 'title' => __('Main menu')],
                ['id' => self::PREFIX.'info', 'title' => __('Timings & location')],
            ]));
    }

    private function bookable(int $timestamp): bool
    {
        if (! $timestamp) {
            return false;
        }

        $start = CarbonImmutable::createFromTimestamp($timestamp);

        return collect($this->reservations->slots($this->local($start)->toDateString()))
            ->contains(fn (array $slot) => CarbonImmutable::parse($slot['starts_at'])->equalTo($start));
    }
}
