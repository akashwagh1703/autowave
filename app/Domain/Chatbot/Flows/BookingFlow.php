<?php

namespace App\Domain\Chatbot\Flows;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\BookingResource;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Services\ChatbotContent;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Support\Interactive;
use App\Domain\Service\Models\Service;
use App\Domain\Website\Services\OnlineBooking;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Booking an appointment or a slot in the chat: service (with the service engine) → who or which resource
 * (when there is a choice) → day → time → confirm. Same rules as booking on the website (OnlineBooking:
 * notice, how far ahead, "any available", auto-confirm).
 *
 * Options: `aw.bk.cat.{category}.{page}`, `aw.bk.svc.{service}`, `aw.bk.res.{service}.{resource}`,
 * `aw.bk.days.{service}.{resource}.{offset}`, `aw.bk.day.{service}.{resource}.{Ymd}`,
 * `aw.bk.part.{service}.{resource}.{Ymd}.{part}.{page}`, `aw.bk.at.{service}.{resource}.{timestamp}`,
 * `aw.bk.ok.…` (book), `aw.bk.no` (cancel). 0 means no service / any resource.
 */
class BookingFlow extends ChatFlow
{
    public function __construct(
        ChatbotContent $content,
        private readonly OnlineBooking $booking,
        private readonly BookingSettings $settings,
    ) {
        parent::__construct($content);
    }

    public function key(): string
    {
        return 'bk';
    }

    /** @return array{body: string, interactive: ?array<string, mixed>} */
    public function start(?int $serviceId): array
    {
        if ($this->booking->usesServices()) {
            return $serviceId && $this->service($serviceId) ? $this->afterService($serviceId) : $this->services(null, 0);
        }

        return $this->afterService(0);
    }

    public function handle(ChatbotSession $session, Conversation $conversation, array $args): ?array
    {
        $step = $args[0] ?? null;
        $service = self::int($args[1] ?? null);
        $resource = self::int($args[2] ?? null);

        return match ($step) {
            'cat' => $this->services($args[1] ?? 'all', self::int($args[2] ?? null)),
            'svc' => $this->afterService($service),
            'res' => $this->days($service, $resource, 0),
            'days' => $this->days($service, $resource, self::int($args[3] ?? null)),
            'day' => $this->times($service, $resource, self::date($args[3] ?? null), null, 0),
            'part' => $this->times($service, $resource, self::date($args[3] ?? null), in_array($args[4] ?? null, ['m', 'a', 'e'], true) ? $args[4] : null, self::int($args[5] ?? null)),
            'at' => $this->confirm($session, $conversation, $service, $resource, self::int($args[3] ?? null)),
            'ok' => $this->book($session, $conversation, $service, $resource, self::int($args[3] ?? null)),
            'no' => $this->cancelled(__('No problem, nothing was booked.')),
            default => null,
        };
    }

    /**
     * Services to choose from; by category when there are many.
     *
     * @param  string|null  $category  null to decide, `all`, `0` for services without a category, or a category id
     */
    private function services(?string $category, int $page): array
    {
        $services = $this->booking->services();

        if ($services->isEmpty()) {
            return $this->failed(__('Online booking is not available right now.'), 'book');
        }

        $categories = $services->map(fn (Service $service) => $service->category)->filter()->unique('id')->values();

        if ($category === null && $services->count() > Interactive::MAX_ROWS && $categories->count() > 1) {
            $rows = $categories->map(fn ($category) => [
                'id' => $this->id('cat', $category->id, 0),
                'title' => $category->name,
                'description' => trans_choice(':count service|:count services', $services->where('category.id', $category->id)->count()),
            ])->values()->all();

            if ($services->whereNull('category')->isNotEmpty()) {
                $rows[] = ['id' => $this->id('cat', 0, 0), 'title' => __('Other services')];
            }

            return $this->reply(__('What would you like to book?'), Interactive::list(__('Choose'), array_slice($rows, 0, Interactive::MAX_ROWS), $this->label()));
        }

        $category ??= 'all';

        if ($category !== 'all') {
            $services = $category === '0' ? $services->whereNull('category') : $services->where('category.id', self::int($category));
        }

        $rows = $services->map(fn (Service $service) => [
            'id' => $this->id('svc', $service->id),
            'title' => $service->name,
            'description' => $this->content->serviceSummary($service),
        ])->values()->all();

        return $this->reply(
            __('What would you like to book?').$this->websiteLine('#booking', __('You can also book on our website:')),
            Interactive::list(__('Choose'), $this->page($rows, $page, fn (int $next) => $this->id('cat', $category, $next), __('More services')), $this->label()),
        );
    }

    /** Who or which resource, when the customer may choose; otherwise straight to the days. */
    private function afterService(int $serviceId): array
    {
        $service = $serviceId ? $this->service($serviceId) : null;

        if ($serviceId && ! $service) {
            return $this->services(null, 0);
        }

        $resources = $this->offering($service);

        if ($resources->count() <= 1) {
            return $this->days($serviceId, (int) $resources->first()?->id, 0);
        }

        $any = $this->settings->online()['allow_any_resource'];
        $rows = $resources->map(fn (BookingResource $resource) => array_filter([
            'id' => $this->id('res', $serviceId, $resource->id),
            'title' => $resource->name,
            'description' => ! $service && $resource->hourly_rate !== null ? $this->content->money($resource->hourly_rate).'/hr' : null,
        ], fn ($value) => $value !== null))->values()->all();

        if ($any) {
            array_unshift($rows, ['id' => $this->id('res', $serviceId, 0), 'title' => __('Any available'), 'description' => __('The first one free')]);
        }

        $question = $service
            ? __('Who would you like for *:service*?', ['service' => $service->name])
            : __('Which one would you like to book?');

        return $this->reply($question, Interactive::list(__('Choose'), array_slice($rows, 0, Interactive::MAX_ROWS), $this->label()));
    }

    /** Days with at least one free time, from the start of the booking window plus `$offset` days. */
    private function days(int $serviceId, int $resourceId, int $offset): array
    {
        $window = $this->booking->window();
        $timezone = TenantTime::timezone();
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $window['first'], $timezone)->addDays($offset);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $window['last'], $timezone);
        $rows = [];
        $next = null;

        try {
            while ($cursor <= $last) {
                $slots = $this->booking->slots($serviceId ?: null, $resourceId ?: null, $cursor->toDateString());

                if ($slots !== []) {
                    if (count($rows) === Interactive::MAX_ROWS - 1) {
                        $next = (int) CarbonImmutable::createFromFormat('!Y-m-d', $window['first'], $timezone)->diffInDays($cursor);
                        break;
                    }

                    $rows[] = [
                        'id' => $this->id('day', $serviceId, $resourceId, self::ymd($cursor->toDateString())),
                        'title' => $this->day($cursor),
                        'description' => trans_choice(':count time free, from :time|:count times free, from :time', count($slots), ['time' => $this->time($slots[0]['starts_at'])]),
                    ];
                }

                $cursor = $cursor->addDay();
            }
        } catch (ValidationException $exception) {
            return $this->failed(self::firstError($exception), 'book');
        }

        if ($rows === []) {
            return $this->failed($offset === 0
                ? __('Sorry, there are no free times in the next few days. Tap *Ask us* and tell us when suits you.')
                : __('Sorry, there are no more free days.'), 'book');
        }

        if ($next !== null) {
            $rows[] = ['id' => $this->id('days', $serviceId, $resourceId, $next), 'title' => __('Later dates')];
        }

        return $this->reply(__('Which day suits you?'), Interactive::list(__('Choose a day'), $rows, $this->label()));
    }

    private function times(int $serviceId, int $resourceId, ?string $date, ?string $part, int $page): array
    {
        if ($date === null) {
            return $this->days($serviceId, $resourceId, 0);
        }

        try {
            $slots = $this->slots($serviceId, $resourceId, $date);
        } catch (ValidationException) {
            return $this->days($serviceId, $resourceId, 0);
        }

        if ($slots === []) {
            $days = $this->days($serviceId, $resourceId, 0);

            return [...$days, 'body' => __('Sorry, :day is full now.', ['day' => $this->day($date)])."\n\n".$days['body']];
        }

        $rows = $this->timeRows(
            $slots,
            $part === 'all' ? null : $part,
            $page,
            fn (int $timestamp) => $this->id('at', $serviceId, $resourceId, $timestamp),
            fn (string $code, int $next) => $this->id('part', $serviceId, $resourceId, self::ymd($date), $code, $next),
        );

        return $this->reply(__('What time on :day?', ['day' => $this->day($date)]), Interactive::list(__('Choose a time'), $rows, $this->label()));
    }

    private function confirm(ChatbotSession $session, Conversation $conversation, int $serviceId, int $resourceId, int $timestamp): array
    {
        $start = $timestamp ? CarbonImmutable::createFromTimestamp($timestamp) : null;
        $service = $serviceId ? $this->service($serviceId) : null;

        if (! $start || ($serviceId && ! $service)) {
            return $this->start(null);
        }

        $date = $this->local($start)->toDateString();
        $slot = collect($this->safeSlots($serviceId, $resourceId, $date))->first(fn (array $slot) => CarbonImmutable::parse($slot['starts_at'])->equalTo($start));

        if (! $slot) {
            $times = $this->times($serviceId, $resourceId, $date, null, 0);

            return [...$times, 'body' => __('Sorry, that time is no longer free.')."\n\n".$times['body']];
        }

        $name = $this->name($session, $conversation);

        if ($name === null) {
            return $this->askName($session, ['at', $serviceId, $resourceId, $timestamp]);
        }

        $resource = $resourceId ? $this->offering($service)->firstWhere('id', $resourceId) : null;
        $price = $service?->price !== null ? $this->content->money($service->price) : ($slot['price'] ? $this->content->money($slot['price']).($slot['price_varies'] ? ' '.__('or more') : '') : null);
        $lines = array_filter([
            __('*Please check your booking*'),
            '',
            $service ? '✨ '.$service->name.' ('.trans_choice(':count min|:count mins', $this->booking->duration($service)).')' : null,
            $resource ? '👤 '.$resource->name : ($this->offering($service)->count() > 1 ? '👤 '.__('Any available') : null),
            '📅 '.$this->when($start),
            $price ? '💰 '.$price : null,
            '🙍 '.$name,
            '',
            $this->settings->online()['auto_confirm'] ? null : __('We will confirm it here shortly.'),
        ], fn ($line) => $line !== null);

        return $this->reply(trim(implode("\n", $lines)), Interactive::buttons([
            ['id' => $this->id('ok', $serviceId, $resourceId, $timestamp), 'title' => __('Confirm booking')],
            ['id' => $this->id('day', $serviceId, $resourceId, self::ymd($date)), 'title' => __('Change time')],
            ['id' => $this->id('no'), 'title' => __('Cancel')],
        ]));
    }

    private function book(ChatbotSession $session, Conversation $conversation, int $serviceId, int $resourceId, int $timestamp): array
    {
        $name = $this->name($session, $conversation);

        if (! $timestamp || $name === null) {
            return $this->confirm($session, $conversation, $serviceId, $resourceId, $timestamp);
        }

        if ($this->alreadyDone($session, "bk.{$serviceId}.{$resourceId}.{$timestamp}")) {
            return $this->reply(__('You are already booked for :when. 👍', ['when' => $this->when($timestamp)]), $this->menuButtons());
        }

        try {
            $appointment = $this->booking->book([
                'service_id' => $serviceId ?: null,
                'resource_id' => $resourceId ?: null,
                'starts_at' => CarbonImmutable::createFromTimestamp($timestamp)->toIso8601String(),
                'name' => $name,
                'phone' => $this->phone($conversation),
                'email' => $this->email($conversation),
            ], 'whatsapp');
        } catch (ValidationException $exception) {
            $session->put('done', array_values(array_diff($session->get('done', []), ["bk.{$serviceId}.{$resourceId}.{$timestamp}"])) ?: null);

            if (array_key_exists('starts_at', $exception->errors())) {
                $times = $this->times($serviceId, $resourceId, $this->local($timestamp)->toDateString(), null, 0);

                return [...$times, 'body' => self::firstError($exception)."\n\n".$times['body']];
            }

            return $this->failed(self::firstError($exception), 'book');
        }

        $this->linkCustomer($conversation, $appointment->customer_id);
        $what = implode(' ', array_filter([
            $appointment->service ? '*'.$appointment->service->name.'*' : null,
            $appointment->resource ? __('with :name', ['name' => $appointment->resource->name]) : null,
        ]));
        $when = $this->when($appointment->starts_at->toImmutable());

        $body = $appointment->status === AppointmentStatus::Confirmed
            ? __('✅ You are booked! :what on :when. See you then!', ['what' => $what, 'when' => $when])
            : __('🙏 Thank you, :name! We have your booking request for :what on :when. We will confirm it here shortly.', ['name' => $name, 'what' => $what, 'when' => $when]);

        return $this->reply(preg_replace('/ {2,}/', ' ', $body) ?? $body, Interactive::buttons([
            ['id' => self::PREFIX.'menu', 'title' => __('Main menu')],
            ['id' => self::PREFIX.'info', 'title' => __('Timings & location')],
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function slots(int $serviceId, int $resourceId, string $date): array
    {
        $service = $serviceId ? $this->service($serviceId) : null;

        return array_map(fn (array $slot) => [
            ...$slot,
            'description' => ! $service && $slot['price'] ? $this->content->money($slot['price']).($slot['price_varies'] ? '+' : '') : null,
        ], $this->booking->slots($serviceId ?: null, $resourceId ?: null, $date));
    }

    /** @return list<array<string, mixed>> */
    private function safeSlots(int $serviceId, int $resourceId, string $date): array
    {
        try {
            return $this->booking->slots($serviceId ?: null, $resourceId ?: null, $date);
        } catch (ValidationException) {
            return [];
        }
    }

    private function service(int $id): ?Service
    {
        return $this->booking->services()->firstWhere('id', $id);
    }

    /** @return Collection<int, BookingResource> */
    private function offering(?Service $service): Collection
    {
        return $this->booking->resources()
            ->filter(fn (BookingResource $resource) => ! $service || $resource->services->contains('id', $service->id))
            ->values();
    }

    private function label(): string
    {
        return in_array($this->content->typeCode(), ['turf', 'sports'], true) ? __('Book a slot') : __('Book an appointment');
    }
}
