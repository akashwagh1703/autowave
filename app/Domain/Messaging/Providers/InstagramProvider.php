<?php

namespace App\Domain\Messaging\Providers;

use App\Domain\Messaging\Contracts\MessagingProvider;
use App\Domain\Messaging\Exceptions\MetaApiException;
use App\Domain\Messaging\Exceptions\PermanentDeliveryFailure;
use App\Domain\Messaging\Meta\MetaGraphClient;
use App\Domain\Messaging\Models\OutboundMessage;
use App\Domain\Messaging\Support\ChannelResolver;
use App\Domain\Messaging\Support\Interactive;

/** Instagram DMs through the Instagram API with Instagram Login. Text replies only; options go as numbered lines. */
class InstagramProvider implements MessagingProvider
{
    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly ChannelResolver $channels,
    ) {}

    public function send(OutboundMessage $message): string
    {
        if ($message->channel !== 'instagram') {
            throw new PermanentDeliveryFailure("The Instagram provider cannot send {$message->channel} messages.");
        }

        $channel = $this->channels->connected('instagram')
            ?? throw new PermanentDeliveryFailure('Instagram is not connected for this business.');

        if ($message->entry()->first()?->attachment()->exists()) {
            throw new PermanentDeliveryFailure('Files cannot be sent on Instagram yet.');
        }

        try {
            return $this->client->sendInstagram((string) $channel->external_id, (string) $channel->credential('access_token'), $message->recipient, Interactive::fallbackText($message->body, $message->interactive));
        } catch (MetaApiException $exception) {
            throw $exception->isPermanent() ? new PermanentDeliveryFailure($exception->getMessage(), 0, $exception) : $exception;
        }
    }
}
