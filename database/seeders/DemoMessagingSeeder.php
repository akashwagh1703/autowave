<?php

namespace Database\Seeders;

use App\Domain\Messaging\Actions\ManageConversation;
use App\Domain\Messaging\Actions\ReceiveInboundMessage;
use App\Domain\Messaging\Actions\ReplyToConversation;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Inbound\InboundMessage;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Support\ContactHandle;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local demo inbox for ABC Salon: WhatsApp and Instagram conversations fed through the same pipeline
 * as Meta webhooks, a team reply, an opted-out contact and two sample templates. No channel is
 * connected, so everything stays simulated. Runs once (skips if the salon already has conversations).
 */
class DemoMessagingSeeder extends Seeder
{
    public function run(TenantContext $context, ReceiveInboundMessage $receive, ReplyToConversation $reply, ManageConversation $manage): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoMessagingSeeder must not run in production.');
        }

        $salon = Tenant::query()->where('slug', 'abc-salon')->first();

        if (! $salon) {
            return;
        }

        $context->run($salon, function () use ($receive, $reply, $manage) {
            if (Conversation::query()->exists()) {
                return;
            }

            $owner = User::query()->where('email', 'owner@abc-salon.test')->first();
            $now = CarbonImmutable::now('UTC');
            $in = fn (string $channel, string $from, string $text, CarbonImmutable $at, ?string $name = null) => $receive->handle(new InboundMessage(
                channel: $channel,
                handle: ContactHandle::for($channel, $from),
                providerMessageId: 'demo-'.Str::uuid(),
                type: 'text',
                text: $text,
                occurredAt: $at,
                name: $name,
            ));

            foreach ([
                ['appointment_reminder', 'UTILITY', 'Hi {{1}}, this is a reminder of your appointment at ABC Salon on {{2}}. Reply here to reschedule.', 2],
                ['we_miss_you', 'MARKETING', 'Hi {{1}}, it has been a while! Enjoy 15% off your next visit this month.', 1],
            ] as [$name, $category, $body, $variables]) {
                MessageTemplate::query()->create([
                    'channel' => 'whatsapp', 'name' => $name, 'language' => 'en', 'category' => $category,
                    'status' => MessageTemplate::APPROVED, 'body' => $body, 'variables' => $variables, 'synced_at' => $now,
                ]);
            }

            // A known customer asking to rebook, answered by the owner.
            $kavya = $in('whatsapp', '98765 10001', 'Hi! Can I book a hair spa for Saturday afternoon?', $now->subHours(3), 'Kavya Rao');
            if ($owner && $kavya) {
                $conversation = Conversation::query()->findOrFail($kavya->conversation_id);
                $manage->markRead($conversation);
                $reply->text($conversation, 'Hi Kavya! Saturday 3 pm is free with Meera. Shall I confirm it?', $owner);
            }
            $in('whatsapp', '98765 10001', 'Yes please 🙏', $now->subHours(2), 'Kavya Rao');

            // New enquiries: these become leads.
            $in('whatsapp', '98200 55501', 'What is the price for bridal makeup?', $now->subMinutes(40), 'Sneha Patil');
            $in('whatsapp', '98200 55501', 'Also do you do trials?', $now->subMinutes(38), 'Sneha Patil');
            $in('instagram', '5566778899001', 'Love your nail art posts! Do you take walk-ins?', $now->subMinutes(15), 'priya.designs');

            // A contact who opted out, and an older, closed conversation.
            $in('whatsapp', '98200 55502', 'STOP', $now->subDays(2), 'Arjun Mehta');
            $old = $in('whatsapp', '98200 55503', 'Thanks, the haircut was great!', $now->subDays(5), 'Vikram Joshi');
            if ($old) {
                $manage->setStatus(Conversation::query()->findOrFail($old->conversation_id), ConversationStatus::Closed);
            }
        });
    }
}
