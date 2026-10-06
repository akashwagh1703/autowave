<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\AI\Services\AIGateway;
use App\Domain\AI\Services\AIService;
use App\Domain\Chatbot\Support\ChatbotSettings;
use App\Domain\Education\Models\Batch;
use App\Domain\Education\Models\Course;
use App\Domain\Messaging\Models\Conversation;
use Illuminate\Support\Str;
use Throwable;

/**
 * AI answers to typed questions in the WhatsApp assistant (ADR-021 step 3). Off unless the owner turns on
 * "Answer questions with AI" and AI is available (module, switched on, configured, under the monthly cap).
 * Any failure counts as "no answer", so the assistant falls back to the menu and the team.
 */
class ChatbotAI
{
    /** Menu items AI may point to with a button under its answer. */
    public const TOPICS = ['book', 'reserve', 'order', 'services', 'rates', 'courses', 'offers', 'info'];

    public function __construct(
        private readonly ChatbotSettings $settings,
        private readonly AIGateway $gateway,
        private readonly AIService $ai,
        private readonly ChatbotContent $content,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->all()['ai_answers'] && $this->gateway->available();
    }

    /**
     * @param  list<string>  $items  the menu items on offer
     * @param  array<string, string>  $actions  item => button label, for booking, reserving or ordering
     * @return array{answer: ?string, topic: ?string}|null null when AI could not be asked
     */
    public function answer(Conversation $conversation, string $question, array $items, array $actions, bool $firstMessage): ?array
    {
        try {
            return $this->ai->answerCustomer(
                $conversation,
                $question,
                $this->facts($items),
                array_values(array_intersect(self::TOPICS, $items)),
                array_values($actions),
                $firstMessage,
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * What the assistant knows besides the general business facts: contact details, the website, offers,
     * common questions, courses, rates, and what the customer can do in the chat.
     *
     * @param  list<string>  $items
     */
    private function facts(array $items): string
    {
        $contact = $this->content->contact();
        $website = $this->content->website();
        $lines = array_filter([
            $contact['hours'] ? 'Opening hours: '.$contact['hours'] : null,
            $contact['email'] ? 'E-mail: '.$contact['email'] : null,
            $website ? 'Website: '.$website : null,
            $contact['map_url'] ? 'Map: '.$contact['map_url'] : null,
        ]);

        $can = array_values(array_filter([
            in_array('book', $items, true) ? 'book an appointment or slot' : null,
            in_array('reserve', $items, true) ? 'reserve a table' : null,
            in_array('order', $items, true) ? 'order for pickup or delivery' : null,
            in_array('courses', $items, true) ? 'book a free demo class' : null,
        ]));

        if ($can !== []) {
            $lines[] = 'In this WhatsApp chat customers can '.implode(', ', $can).' by tapping the buttons; the team confirms.';
        }

        if (in_array('rates', $items, true)) {
            $lines[] = 'Hourly rates:';

            foreach ($this->content->rates() as $resource) {
                $lines[] = '- '.$resource->name.': '.$this->content->money($resource->hourly_rate).'/hr';
            }
        }

        if (in_array('courses', $items, true)) {
            $lines[] = 'Courses:';

            foreach ($this->content->courses()->take(15) as $course) {
                /** @var Course $course */
                $batches = $course->batches->map(fn (Batch $batch) => $this->content->batchLine($batch))->implode('; ');
                $lines[] = '- '.$course->name.(($summary = $this->content->courseSummary($course)) !== '' ? ': '.$summary : '').($batches !== '' ? ' (batches: '.$batches.')' : '');
            }
        }

        $offers = $this->content->offers();

        if ($offers !== []) {
            $lines[] = 'Current offers:';

            foreach (array_slice($offers, 0, 10) as $offer) {
                $lines[] = '- '.$offer['title'].($offer['price'] ? ' — '.$offer['price'] : '').($offer['description'] ? ': '.Str::limit($offer['description'], 200) : '').($offer['valid_until'] ? ' (valid until '.$offer['valid_until'].')' : '');
            }
        }

        $faq = $this->content->faq();

        if ($faq !== []) {
            $lines[] = 'Common questions:';

            foreach (array_slice($faq, 0, 15) as $item) {
                $lines[] = '- Q: '.Str::limit($item['question'], 160).' A: '.Str::limit($item['answer'], 300);
            }
        }

        return implode("\n", $lines);
    }
}
