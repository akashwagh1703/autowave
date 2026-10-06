<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\Chatbot\Actions\AlertTeamAboutChat;
use App\Domain\Chatbot\Models\ChatbotSession;
use App\Domain\Chatbot\Support\ChatbotSettings;
use App\Domain\Lead\Actions\UpdateLead;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationMessage;
use App\Domain\Messaging\Support\Interactive;
use Illuminate\Support\Str;
use Throwable;

/**
 * Decides the WhatsApp assistant's answer to one message (ADR-021). A menu built from what the business
 * offers; the contact taps a button or list row (option ids `aw.<item>[.<detail>]`), types its number,
 * or types a keyword. Questions and requests for a person are handed to the team: the assistant pauses
 * for the chat and the owners get an email.
 *
 * Changes the session in memory; the caller saves it and sends the reply.
 */
class ChatbotEngine
{
    private const PREFIX = 'aw.';

    /** @var array{enabled: bool, welcome: ?string, show_image: bool, items: list<string>, pause_hours: int, alert_team: bool} */
    private array $settings;

    /** @var list<string> menu items enabled and available, in order */
    private array $items;

    public function __construct(
        private readonly ChatbotContent $content,
        private readonly ChatbotSettings $chatbotSettings,
        private readonly AlertTeamAboutChat $alert,
        private readonly UpdateLead $updateLead,
    ) {}

    /** @return array{body: string, interactive: ?array<string, mixed>}|null null when the assistant stays quiet */
    public function respond(ChatbotSession $session, Conversation $conversation, ConversationMessage $message): ?array
    {
        $this->load();
        $fresh = $session->isStale();

        if ($fresh) {
            $session->forceFill(['state' => ChatbotSession::MENU, 'data' => null, 'misses' => 0]);
        }

        $text = trim((string) $message->body);
        // While waiting for a question, typed words are the question; only "menu" or a tap leaves.
        $choice = $this->choice($session, $message, $text, $session->state === ChatbotSession::QUESTION);

        if ($choice === null && $session->state === ChatbotSession::QUESTION && $message->type === 'text' && $text !== '') {
            return $this->remember($session, $this->handOver($session, $conversation, 'question', $text));
        }

        if ($choice !== null) {
            $session->forceFill(['state' => ChatbotSession::MENU, 'misses' => 0]);
            // A typed "hi" after a while gets the welcome; a tapped "More options" always opens the menu.
            $typed = ! isset($message->meta['reply_id']);
            $reply = $choice === 'aw.menu' && $fresh && $typed ? $this->welcome($conversation, false) : $this->open($session, $conversation, $choice);

            if ($reply !== null) {
                return $this->remember($session, $reply);
            }
        }

        if ($fresh) {
            return $this->remember($session, $this->welcome($conversation, $message->type === 'text' && $text !== ''));
        }

        // Photos, voice notes and the like in the middle of a chat are for the team.
        if (! in_array($message->type, ['text', 'interactive'], true)) {
            return null;
        }

        $session->misses++;

        if ($session->misses >= (int) config('chatbot.misses_before_handover')) {
            return $this->remember($session, $this->handOver($session, $conversation, 'unclear', $text));
        }

        return $this->remember($session, $this->reply(
            __('I’m the automatic assistant, so I can only help with the options below. For anything else, tap *Talk to us* and our team will reply.'),
            Interactive::buttons([
                ['id' => 'aw.menu', 'title' => __('See options')],
                ['id' => 'aw.human', 'title' => __('Talk to us')],
            ]),
        ));
    }

    /** The welcome when the owner has not written one; {name} is the contact's first name. */
    public static function defaultWelcome(): string
    {
        return __('Hi {name}! 👋 Welcome to {business}. How can we help you today?');
    }

    private function load(): void
    {
        $this->settings = $this->chatbotSettings->all();
        $availability = $this->content->availability();
        $this->items = array_values(array_filter($this->settings['items'], fn (string $item) => $availability[$item] ?? false));
    }

    /** @return array{button: string, title: string, description: string} */
    public function label(string $item): array
    {
        $turf = in_array($this->content->typeCode(), ['turf', 'sports'], true);

        return match ($item) {
            'book' => $turf
                ? ['button' => __('Book a slot'), 'title' => __('Book a slot'), 'description' => __('Pick a day and time to play')]
                : ['button' => __('Book now'), 'title' => __('Book an appointment'), 'description' => __('Pick a day and time that suits you')],
            'reserve' => ['button' => __('Reserve a table'), 'title' => __('Reserve a table'), 'description' => __('Book a table for your group')],
            'order' => ['button' => __('Order online'), 'title' => __('Order online'), 'description' => __('Order for pickup or delivery')],
            'services' => ['button' => __('Services & prices'), 'title' => __('Services & prices'), 'description' => __('What we offer and what it costs')],
            'rates' => ['button' => __('Rates'), 'title' => Str::ucfirst($this->content->resourceLabel()).' & rates', 'description' => __('Hourly rates')],
            'courses' => ['button' => __('Courses & fees'), 'title' => __('Courses & fees'), 'description' => __('Courses, batches and fees')],
            'offers' => ['button' => __('Offers'), 'title' => __('Offers'), 'description' => __('Current deals and discounts')],
            'faq' => ['button' => __('Questions'), 'title' => __('Common questions'), 'description' => __('Quick answers to what people ask')],
            'info' => ['button' => __('Timings & location'), 'title' => __('Timings & location'), 'description' => __('Address, hours, map and website')],
            'human' => ['button' => __('Talk to us'), 'title' => __('Talk to a person'), 'description' => __('Someone from our team will reply')],
            default => ['button' => $item, 'title' => $item, 'description' => ''],
        };
    }

    /** What the contact chose: a tapped option, a typed number from the last options, or a keyword. */
    private function choice(ChatbotSession $session, ConversationMessage $message, string $text, bool $menuOnly): ?string
    {
        $replyId = $message->meta['reply_id'] ?? null;

        if (is_string($replyId) && str_starts_with($replyId, self::PREFIX)) {
            return $replyId;
        }

        if ($message->type !== 'text' || $text === '') {
            return null;
        }

        $normalised = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($text)) ?? '') ?? '');
        $keywords = config('chatbot.keywords');

        if ($menuOnly) {
            return in_array($normalised, $keywords['menu'], true) ? 'aw.menu' : null;
        }

        if (preg_match('/^\d{1,2}$/', $text) === 1) {
            return $session->data['options'][(int) $text - 1] ?? null;
        }

        if ($normalised === '' || count(explode(' ', $normalised)) > (int) config('chatbot.keyword_max_words')) {
            return null;
        }

        $padded = " {$normalised} ";
        $matches = fn (string $item) => collect($keywords[$item] ?? [])->contains(fn (string $word) => str_contains($padded, " {$word} "));

        foreach ($this->items as $item) {
            if ($matches($item)) {
                return self::PREFIX.$item;
            }
        }

        return $matches('menu') ? 'aw.menu' : null;
    }

    /** @return array{body: string, interactive: ?array<string, mixed>}|null */
    private function open(ChatbotSession $session, Conversation $conversation, string $choice): ?array
    {
        $parts = explode('.', substr($choice, strlen(self::PREFIX)));
        $item = $parts[0];
        $id = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null;
        $offered = fn (string $key) => in_array($key, $this->items, true);

        return match (true) {
            $item === 'menu' => $this->mainMenu(),
            $item === 'home' => $this->welcome($conversation, false),
            in_array($item, ['book', 'reserve', 'order'], true) && $offered($item) => $this->online($session, $item, $parts[1] ?? null),
            $item === 'services' && $offered('services') => $this->servicesList($id),
            $item === 'svc' && $offered('services') && $id !== null => $this->serviceDetail($id),
            $item === 'rates' && $offered('rates') => $this->rates(),
            $item === 'courses' && $offered('courses') => $this->coursesList(),
            $item === 'course' && $offered('courses') && $id !== null => $this->courseDetail($id),
            $item === 'offers' && $offered('offers') => $this->offers(),
            $item === 'faq' && $offered('faq') => $id !== null ? $this->faqAnswer($id) : $this->faqList(),
            $item === 'info' => $this->info(),
            $item === 'human' => $this->handOver($session, $conversation, 'asked', null),
            $item === 'ask' => $this->ask($session, $parts[1] ?? null, isset($parts[2]) && ctype_digit($parts[2]) ? (int) $parts[2] : null),
            default => null,
        };
    }

    /** @return array{body: string, interactive: ?array<string, mixed>} */
    private function welcome(Conversation $conversation, bool $acknowledge): array
    {
        $name = $conversation->contact_name ?? $conversation->lead?->name ?? $conversation->customer?->name;
        $first = $name ? Str::of($name)->trim()->explode(' ')->first() : null;
        $body = strtr($this->settings['welcome'] ?? self::defaultWelcome(), ['{name}' => $first ?? __('there'), '{business}' => $this->content->name()]);

        if ($acknowledge) {
            $body .= "\n\n".__('We have your message and our team will see it too.');
        }

        $buttons = $this->topButtons();
        $image = $this->settings['show_image'] ? $this->content->welcomeImage() : null;

        return $this->reply($body, Interactive::buttons($buttons, null, $image));
    }

    /**
     * Up to three buttons: the main action and what the business offers (a salon gets "Book now" and
     * "Services & prices" before "Order online"), then "More options" for the full list.
     *
     * @return list<array{id: string, title: string}>
     */
    private function topButtons(): array
    {
        $button = fn (string $item) => ['id' => self::PREFIX.$item, 'title' => $this->label($item)['button']];

        if (count($this->items) <= Interactive::MAX_BUTTONS) {
            return array_map($button, $this->items);
        }

        $actions = array_values(array_intersect(['book', 'reserve', 'order'], $this->items));
        $catalogue = array_values(array_intersect(['services', 'courses', 'rates'], $this->items));
        $first = array_values(array_unique([...array_slice($actions, 0, 1), ...array_slice($catalogue, 0, 1), ...array_diff($this->items, ['human'])]));

        return [...array_map($button, array_slice($first, 0, 2)), ['id' => 'aw.menu', 'title' => __('More options')]];
    }

    /** @return array{body: string, interactive: ?array<string, mixed>} */
    private function mainMenu(): array
    {
        $rows = array_map(fn (string $item) => ['id' => self::PREFIX.$item, ...array_intersect_key($this->label($item), array_flip(['title', 'description']))], $this->items);

        return $this->reply(__('What would you like to do? Tap *See options* to choose.'), Interactive::list(__('See options'), $rows, $this->content->name()));
    }

    /** Booking, reservations and orders happen on the website for now; without a live website the team takes the request. */
    private function online(ChatbotSession $session, string $item, ?string $detail): array
    {
        $website = $this->content->website();
        $anchor = ['book' => '#booking', 'reserve' => '#reservation', 'order' => '#products'][$item];
        $service = $item === 'book' && $detail !== null && ctype_digit($detail) ? $this->content->service((int) $detail) : null;

        if ($website === null) {
            return $this->ask($session, $item, $service?->id);
        }

        $intro = match ($item) {
            'book' => $service ? __('You can book *:service* online in under a minute:', ['service' => $service->name]) : __('You can book online in under a minute:'),
            'reserve' => __('You can reserve a table online in under a minute:'),
            default => __('You can see everything and order online here:'),
        };
        $fallback = match ($item) {
            'book' => __('Or tap *Ask us* and tell us the day and time you would like; we will confirm here.'),
            'reserve' => __('Or tap *Ask us* and tell us the day, time and number of people; we will confirm here.'),
            default => __('Or tap *Ask us* and tell us what you would like to order.'),
        };

        return $this->reply($intro."\n".$website.'/'.$anchor."\n\n".$fallback, Interactive::buttons([
            ['id' => 'aw.ask.'.$item.($service ? '.'.$service->id : ''), 'title' => __('Ask us')],
            ['id' => 'aw.menu', 'title' => __('Main menu')],
        ]));
    }

    private function servicesList(?int $categoryId): array
    {
        $services = $this->content->services();
        $categories = $services->map(fn ($service) => $service->category)->filter()->unique('id')->sortBy([['sort_order', 'asc'], ['name', 'asc']])->values();

        if ($categoryId === null && $services->count() > Interactive::MAX_ROWS && $categories->count() > 1) {
            $rows = $categories->take(Interactive::MAX_ROWS - 1)->map(fn ($category) => [
                'id' => 'aw.services.'.$category->id,
                'title' => $category->name,
                'description' => trans_choice(':count service|:count services', $services->where('category.id', $category->id)->count()),
            ])->values()->all();

            if ($services->whereNull('category')->isNotEmpty() || $categories->count() > Interactive::MAX_ROWS - 1) {
                $rows[] = ['id' => 'aw.services.0', 'title' => __('Other services')];
            }

            return $this->reply(__('Which kind of service are you looking for?'), Interactive::list(__('Choose'), $rows, __('Services & prices')));
        }

        if ($categoryId !== null) {
            $shown = $categories->take(Interactive::MAX_ROWS - 1)->pluck('id')->all();
            $services = $categoryId === 0
                ? $services->filter(fn ($service) => ! in_array($service->category?->id, $shown, true))
                : $services->where('category.id', $categoryId);
        }

        if ($services->isEmpty()) {
            return $this->mainMenu();
        }

        if ($services->count() > Interactive::MAX_ROWS) {
            $lines = $services->map(fn ($service) => '• '.$service->name.' — '.$this->content->serviceSummary($service))->implode("\n");

            return $this->reply(Str::limit(__('*Services & prices*')."\n\n".$lines, Interactive::BODY_MAX - 60), Interactive::buttons(array_values(array_filter([
                in_array('book', $this->items, true) ? ['id' => 'aw.book', 'title' => $this->label('book')['button']] : null,
                ['id' => 'aw.ask.services', 'title' => __('Ask a question')],
                ['id' => 'aw.menu', 'title' => __('Main menu')],
            ]))));
        }

        $rows = $services->map(fn ($service) => ['id' => 'aw.svc.'.$service->id, 'title' => $service->name, 'description' => $this->content->serviceSummary($service)])->values()->all();

        return $this->reply(__('Here is what we offer. Tap a service to see the details.'), Interactive::list(__('See services'), $rows, __('Services & prices')));
    }

    private function serviceDetail(int $id): ?array
    {
        $service = $this->content->service($id);

        if (! $service) {
            return null;
        }

        $body = '*'.$service->name.'*'."\n".$this->content->serviceSummary($service)
            .(filled($service->description) ? "\n\n".Str::limit((string) $service->description, 600) : '');

        return $this->reply($body, Interactive::buttons(array_values(array_filter([
            in_array('book', $this->items, true) ? ['id' => 'aw.book.'.$service->id, 'title' => __('Book this')] : null,
            ['id' => 'aw.ask.svc.'.$service->id, 'title' => __('Ask about this')],
            ['id' => 'aw.menu', 'title' => __('Main menu')],
        ]))));
    }

    private function rates(): array
    {
        $lines = $this->content->rates()->map(function ($resource) {
            $special = collect($resource->rates ?? [])->map(fn (array $rate) => ($rate['label'] ?? __('Special')).' '.$this->content->money($rate['hourly_rate'] ?? 0).'/hr')->implode(', ');

            return '• '.$resource->name.' — '.$this->content->money($resource->hourly_rate).'/hr'.($special !== '' ? " ({$special})" : '');
        })->implode("\n");

        return $this->reply(Str::limit('*'.$this->label('rates')['title'].'*'."\n\n".$lines, Interactive::BODY_MAX - 60), Interactive::buttons(array_values(array_filter([
            in_array('book', $this->items, true) ? ['id' => 'aw.book', 'title' => $this->label('book')['button']] : null,
            ['id' => 'aw.ask.rates', 'title' => __('Ask a question')],
            ['id' => 'aw.menu', 'title' => __('Main menu')],
        ]))));
    }

    private function coursesList(): array
    {
        $rows = $this->content->courses()->take(Interactive::MAX_ROWS)
            ->map(fn ($course) => ['id' => 'aw.course.'.$course->id, 'title' => $course->name, 'description' => $this->content->courseSummary($course)])
            ->values()->all();

        return $this->reply(__('Here are our courses. Tap one to see the batches and fees.'), Interactive::list(__('See courses'), $rows, __('Courses & fees')));
    }

    private function courseDetail(int $id): ?array
    {
        $course = $this->content->course($id);

        if (! $course) {
            return null;
        }

        $batches = $course->batches->map(fn ($batch) => '• '.$this->content->batchLine($batch))->implode("\n");
        $body = '*'.$course->name.'*'
            .(($summary = $this->content->courseSummary($course)) !== '' ? "\n".$summary : '')
            .(filled($course->description) ? "\n\n".Str::limit((string) $course->description, 400) : '')
            .($batches !== '' ? "\n\n".__('*Batches*')."\n".$batches : '');

        return $this->reply(Str::limit($body, Interactive::BODY_MAX - 10), Interactive::buttons([
            ['id' => 'aw.ask.demo.'.$course->id, 'title' => __('Free demo class')],
            ['id' => 'aw.ask.course.'.$course->id, 'title' => __('Ask about this')],
            ['id' => 'aw.menu', 'title' => __('Main menu')],
        ]));
    }

    private function offers(): array
    {
        $lines = collect($this->content->offers())->map(fn (array $offer) => '• *'.$offer['title'].'*'
            .($offer['price'] ? ' — '.$offer['price'] : '')
            .($offer['description'] ? "\n  ".Str::limit($offer['description'], 160) : '')
            .($offer['valid_until'] ? "\n  ".__('Valid until :date', ['date' => $offer['valid_until']]) : ''))
            ->implode("\n\n");

        return $this->reply(Str::limit(__('*Current offers*')."\n\n".$lines, Interactive::BODY_MAX - 10), Interactive::buttons(array_values(array_filter([
            $this->primaryAction(),
            ['id' => 'aw.ask.offers', 'title' => __('Ask about offers')],
            ['id' => 'aw.menu', 'title' => __('Main menu')],
        ]))));
    }

    private function faqList(): array
    {
        $rows = collect($this->content->faq())->take(Interactive::MAX_ROWS)
            ->map(fn (array $item, int $index) => ['id' => 'aw.faq.'.$index, 'title' => $item['question'], 'description' => mb_strlen($item['question']) > 24 ? $item['question'] : null])
            ->values()->all();

        return $this->reply(__('Tap a question to see the answer.'), Interactive::list(__('See questions'), $rows, __('Common questions')));
    }

    private function faqAnswer(int $index): ?array
    {
        $item = $this->content->faq()[$index] ?? null;

        if (! $item) {
            return null;
        }

        return $this->reply(Str::limit('*'.$item['question'].'*'."\n\n".$item['answer'], Interactive::BODY_MAX - 10), Interactive::buttons([
            ['id' => 'aw.faq', 'title' => __('More questions')],
            ['id' => 'aw.human', 'title' => __('Talk to us')],
            ['id' => 'aw.menu', 'title' => __('Main menu')],
        ]));
    }

    private function info(): array
    {
        $contact = $this->content->contact();
        $website = $this->content->website();

        $lines = array_filter([
            '*'.$this->content->name().'*',
            $contact['address'] ? '📍 '.$contact['address'] : null,
            $contact['hours'] ? '🕒 '.$contact['hours'] : null,
            $contact['phone'] ? '📞 '.$contact['phone'] : null,
            $contact['email'] ? '✉️ '.$contact['email'] : null,
            $contact['map_url'] ? '🗺️ '.__('Map').': '.$contact['map_url'] : null,
            $website ? '🌐 '.__('Website').': '.$website : null,
        ]);

        if (count($lines) === 1) {
            $lines[] = __('Tap *Talk to us* and our team will share the details.');
        }

        return $this->reply(Str::limit(implode("\n", $lines), Interactive::BODY_MAX - 10), Interactive::buttons(array_values(array_filter([
            $this->primaryAction(),
            ['id' => 'aw.human', 'title' => __('Talk to us')],
            ['id' => 'aw.menu', 'title' => __('Main menu')],
        ]))));
    }

    /** Asks the contact to type their question or request; the next message goes to the team. */
    private function ask(ChatbotSession $session, ?string $topic, ?int $id): array
    {
        $service = $id !== null && in_array($topic, ['svc', 'book'], true) ? $this->content->service($id) : null;
        $course = $id !== null && in_array($topic, ['course', 'demo'], true) ? $this->content->course($id) : null;

        [$interest, $prompt] = match ($topic) {
            'book' => [$service ? __('Booking: :service', ['service' => $service->name]) : __('Booking'), __('Please tell us the day and time you would like:service, and we will confirm here.', ['service' => $service ? ' ('.$service->name.')' : ''])],
            'reserve' => [__('Table reservation'), __('Please tell us the day, time and number of people, and we will confirm here.')],
            'order' => [__('Order'), __('Please tell us what you would like to order and whether you want pickup or delivery.')],
            'demo' => [__('Free demo: :course', ['course' => $course?->name ?? __('class')]), __('Which day and time suit you for a free demo class? Our team will confirm here.')],
            'svc' => [$service?->name, __('Sure! What would you like to know about :name? Type your question below.', ['name' => $service?->name ?? __('it')])],
            'course' => [$course?->name, __('Sure! What would you like to know about :name? Type your question below.', ['name' => $course?->name ?? __('it')])],
            'offers' => [__('Offers'), __('Sure! Which offer are you interested in? Type your question below.')],
            default => [null, __('Sure! Type your question below and our team will reply.')],
        };

        $session->forceFill([
            'state' => ChatbotSession::QUESTION,
            'data' => [...($session->data ?? []), 'interest' => $interest ? Str::limit($interest, 150, '') : null],
        ]);

        return $this->reply($prompt, null);
    }

    /** Pauses the assistant for this chat, notes the interest on the lead, and tells the owners. */
    private function handOver(ChatbotSession $session, Conversation $conversation, string $reason, ?string $text): array
    {
        $interest = $session->data['interest'] ?? null;
        $name = $conversation->displayName();

        $session->forceFill([
            'state' => ChatbotSession::MENU,
            'misses' => 0,
            'paused_until' => now()->addHours($this->settings['pause_hours']),
            'data' => ['paused_by' => 'contact'],
        ]);

        if ($interest) {
            $this->noteInterest($conversation, $interest);
        }

        if ($this->settings['alert_team']) {
            $intro = match ($reason) {
                'question' => __(':name sent a question to the WhatsApp assistant. Please reply in the inbox.', ['name' => $name]),
                'unclear' => __('The WhatsApp assistant could not help :name. Please reply in the inbox.', ['name' => $name]),
                default => __(':name asked to talk to a person on WhatsApp. Please reply in the inbox.', ['name' => $name]),
            };

            $this->alert->handle($conversation, $intro, $text !== null ? Str::limit($text, 500) : null, $interest);
        }

        $body = match ($reason) {
            'question' => __('Thank you! We have passed this to our team and they will reply here soon.'),
            'unclear' => __('Let me get someone from our team to help you. They will reply here soon.'),
            default => __('Sure! Someone from our team will reply here soon.'),
        };

        return $this->reply($body."\n\n".__('Type *menu* anytime to see the options again.'), null);
    }

    private function noteInterest(Conversation $conversation, string $interest): void
    {
        $lead = $conversation->lead;

        if (! $lead || $lead->trashed() || filled($lead->interest)) {
            return;
        }

        try {
            $this->updateLead->handle($lead, ['interest' => $interest], null, ['via' => 'assistant']);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return array{id: string, title: string}|null the business's main action as a button */
    private function primaryAction(): ?array
    {
        foreach (['book', 'reserve', 'order'] as $item) {
            if (in_array($item, $this->items, true)) {
                return ['id' => self::PREFIX.$item, 'title' => $this->label($item)['button']];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $interactive
     * @return array{body: string, interactive: ?array<string, mixed>}
     */
    private function reply(string $body, ?array $interactive): array
    {
        return ['body' => $body, 'interactive' => $interactive];
    }

    /**
     * Keeps the ids of the options just sent, so a typed "2" picks the second one.
     *
     * @param  array{body: string, interactive: ?array<string, mixed>}  $reply
     * @return array{body: string, interactive: ?array<string, mixed>}
     */
    private function remember(ChatbotSession $session, array $reply): array
    {
        $options = array_column(Interactive::options($reply['interactive']), 'id');
        $data = $session->data ?? [];

        if ($options !== []) {
            $data['options'] = $options;
        } else {
            unset($data['options']);
        }

        $session->data = $data ?: null;
        $session->last_reply_at = now();

        return $reply;
    }
}
