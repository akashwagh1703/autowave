<?php

namespace App\Domain\Chatbot\Flows;

use App\Domain\Chatbot\Actions\AlertTeamAboutChat;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotContent;
use App\Domain\Education\Actions\ScheduleDemo;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Education\Support\BatchSchedule;
use App\Domain\Lead\Enums\StageOutcome;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Support\Interactive;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Booking a free demo class in the chat (coaching): batch (when there is a choice) → a day the batch meets →
 * confirm. The demo is scheduled on the contact's open enquiry (ScheduleDemo), at the batch's start time,
 * and the owners are emailed. Without a batch timetable or an open enquiry the request goes to the team.
 *
 * Options: `aw.dm.c.{course}`, `aw.dm.b.{batch}`, `aw.dm.at.{batch}.{Ymd}`, `aw.dm.ok.{batch}.{Ymd}`, `aw.dm.no`.
 */
class DemoFlow extends ChatFlow
{
    private const DAYS_AHEAD = 14;

    private const MIN_NOTICE_HOURS = 2;

    public function __construct(
        ChatbotContent $content,
        private readonly ScheduleDemo $scheduleDemo,
        private readonly AlertTeamAboutChat $alert,
    ) {
        parent::__construct($content);
    }

    public function key(): string
    {
        return 'dm';
    }

    /** Whether a demo of this course can be booked in the chat. */
    public function offers(Course $course, Conversation $conversation): bool
    {
        return $this->batches($course)->isNotEmpty() && $this->lead($conversation) !== null;
    }

    public function handle(ChatbotSession $session, Conversation $conversation, array $args): ?array
    {
        $id = self::int($args[1] ?? null);

        return match ($args[0] ?? null) {
            'c' => $this->course($conversation, $id),
            'b' => $this->days($id),
            'at' => $this->confirm($id, self::date($args[2] ?? null)),
            'ok' => $this->book($session, $conversation, $id, self::date($args[2] ?? null)),
            'no' => $this->cancelled(__('No problem. Tap *Main menu* whenever you are ready.')),
            default => null,
        };
    }

    private function course(Conversation $conversation, int $id): ?array
    {
        $course = $this->content->course($id);

        if (! $course || ! $this->offers($course, $conversation)) {
            return null;
        }

        $batches = $this->batches($course);

        if ($batches->count() === 1) {
            return $this->days($batches->first()->id);
        }

        $rows = $batches->take(Interactive::MAX_ROWS)->map(fn (Batch $batch) => [
            'id' => $this->id('b', $batch->id),
            'title' => $batch->name,
            'description' => BatchSchedule::describe($batch),
        ])->values()->all();

        return $this->reply(__('Which batch would you like to try for *:course*?', ['course' => $course->name]), Interactive::list(__('Choose a batch'), $rows, __('Free demo class')));
    }

    private function days(int $batchId): ?array
    {
        $batch = $this->batch($batchId);

        if (! $batch) {
            return null;
        }

        $rows = array_map(fn (CarbonImmutable $start) => [
            'id' => $this->id('at', $batch->id, $start->format('Ymd')),
            'title' => $this->day($start),
            'description' => $start->format('g:i a').' · '.$batch->name,
        ], array_slice($this->dates($batch), 0, Interactive::MAX_ROWS));

        if ($rows === []) {
            return $this->failed(__('Sorry, this batch has no classes in the next two weeks.'), 'demo.'.$batch->course_id);
        }

        return $this->reply(__('Which day would you like to come for the demo?'), Interactive::list(__('Choose a day'), $rows, __('Free demo class')));
    }

    private function confirm(int $batchId, ?string $date): ?array
    {
        $batch = $this->batch($batchId);
        $start = $batch && $date ? $this->startOn($batch, $date) : null;

        if (! $batch) {
            return null;
        }

        if (! $start) {
            return $this->days($batchId);
        }

        $body = implode("\n", array_filter([
            __('*Please check your free demo class*'),
            '',
            '📚 '.$batch->course->name.' · '.$batch->name,
            '📅 '.$this->when($start),
            $batch->room ? '📍 '.$batch->room : null,
        ], fn ($line) => $line !== null));

        return $this->reply($body, Interactive::buttons([
            ['id' => $this->id('ok', $batch->id, self::ymd($date)), 'title' => __('Confirm')],
            ['id' => $this->id('b', $batch->id), 'title' => __('Change day')],
            ['id' => $this->id('no'), 'title' => __('Cancel')],
        ]));
    }

    private function book(ChatbotSession $session, Conversation $conversation, int $batchId, ?string $date): ?array
    {
        $batch = $this->batch($batchId);
        $start = $batch && $date ? $this->startOn($batch, $date) : null;
        $lead = $this->lead($conversation);

        if (! $batch || ! $start || ! $lead) {
            return $this->confirm($batchId, $date);
        }

        if ($this->alreadyDone($session, "dm.{$batchId}.{$date}")) {
            return $this->reply(__('Your demo class for :when is already booked. 👍', ['when' => $this->when($start)]), $this->menuButtons());
        }

        try {
            $this->scheduleDemo->handle($lead, [
                'scheduled_at' => $start,
                'course_id' => $batch->course_id,
                'batch_id' => $batch->id,
                'notes' => __('Booked on WhatsApp'),
            ]);
        } catch (ValidationException $exception) {
            return $this->failed(self::firstError($exception), 'demo.'.$batch->course_id);
        }

        $this->alert->notify(
            $conversation,
            __('Free demo booked on WhatsApp: :course', ['course' => $batch->course->name]),
            __(':name booked a free demo class on WhatsApp.', ['name' => $conversation->displayName()]),
            [
                __('Course') => $batch->course->name.' · '.$batch->name,
                __('When') => $this->when($start),
            ],
            __('Open the lead'),
            "/leads/{$lead->id}",
            'demo',
        );

        return $this->reply(
            __('✅ Your free demo class is booked for :when (:course). See you then! If you need to change it, just reply here.', ['when' => $this->when($start), 'course' => $batch->course->name]),
            Interactive::buttons([
                ['id' => self::PREFIX.'menu', 'title' => __('Main menu')],
                ['id' => self::PREFIX.'info', 'title' => __('Timings & location')],
            ]),
        );
    }

    /** @return Collection<int, Batch> active batches with a timetable that are still running */
    private function batches(Course $course): Collection
    {
        $today = TenantTime::now()->toDateString();

        return $course->batches
            ->filter(fn (Batch $batch) => $batch->is_active && $batch->startTime() !== null && ! empty($batch->weekdays)
                && ($batch->ends_on === null || $batch->ends_on->toDateString() >= $today))
            ->values();
    }

    private function batch(int $id): ?Batch
    {
        foreach ($this->content->courses() as $course) {
            $batch = $this->batches($course)->firstWhere('id', $id);

            if ($batch) {
                return $batch->setRelation('course', $course);
            }
        }

        return null;
    }

    /** @return list<CarbonImmutable> class start times in the next two weeks */
    private function dates(Batch $batch): array
    {
        $timezone = TenantTime::timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $dates = [];

        for ($offset = 0; $offset <= self::DAYS_AHEAD; $offset++) {
            $start = $this->startOn($batch, $today->addDays($offset)->toDateString());

            if ($start) {
                $dates[] = $start;
            }
        }

        return $dates;
    }

    /** The batch's class on that local date, when it meets then and there is enough notice. */
    private function startOn(Batch $batch, string $date): ?CarbonImmutable
    {
        $timezone = TenantTime::timezone();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if (! $day || $day < $today || $day > $today->addDays(self::DAYS_AHEAD)
            || ! in_array($day->dayOfWeekIso, array_map('intval', $batch->weekdays ?? []), true)
            || ($batch->starts_on && $date < $batch->starts_on->toDateString())
            || ($batch->ends_on && $date > $batch->ends_on->toDateString())) {
            return null;
        }

        $start = $day->setTimeFromTimeString((string) $batch->startTime());

        return $start->greaterThan(CarbonImmutable::now()->addHours(self::MIN_NOTICE_HOURS)) ? $start : null;
    }

    private function lead(Conversation $conversation): ?Lead
    {
        $lead = $conversation->lead;

        if (! $lead || $lead->trashed()) {
            return null;
        }

        return $lead->loadMissing('stage')->stage?->outcome === StageOutcome::Open ? $lead : null;
    }
}
