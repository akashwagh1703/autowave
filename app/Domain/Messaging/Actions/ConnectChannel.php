<?php

namespace App\Domain\Messaging\Actions;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Messaging\Enums\ChannelStatus;
use App\Domain\Messaging\Exceptions\MetaApiException;
use App\Domain\Messaging\Meta\MetaGraphClient;
use App\Domain\Messaging\Models\MessagingChannel;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connects the tenant's own WhatsApp number or Instagram account (manual connection, ADR-018). The
 * details are checked against Meta before they are saved. A blank token or secret keeps the saved one.
 */
class ConnectChannel
{
    public function __construct(
        private readonly MetaGraphClient $client,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** The channel's row, created with a webhook key and verify token on first use. */
    public static function ensure(string $channel): MessagingChannel
    {
        $row = MessagingChannel::query()->firstOrCreate(['channel' => $channel], ['status' => ChannelStatus::Disconnected]);

        if ($row->verifyToken() === '') {
            $row->forceFill(['credentials' => [...($row->credentials ?? []), 'verify_token' => Str::random(32)]])->save();
        }

        return $row;
    }

    /** @param  array{phone_number_id: string, business_account_id: string, access_token?: ?string, app_secret?: ?string}  $data */
    public function whatsapp(array $data, User $actor): MessagingChannel
    {
        $row = self::ensure('whatsapp');
        [$token, $secret] = $this->secrets($row, $data);
        $this->assertNotTaken('whatsapp', $data['phone_number_id']);

        try {
            $number = $this->client->whatsAppPhoneNumber($data['phone_number_id'], $token);
        } catch (MetaApiException $exception) {
            throw ValidationException::withMessages(['access_token' => __('Meta rejected these details: :error', ['error' => $exception->getMessage()])]);
        }

        return $this->save($row, $actor, [
            'external_id' => $data['phone_number_id'],
            'business_account_id' => $data['business_account_id'],
            'display_name' => $number['verified_name'],
            'display_handle' => $number['display_phone_number'],
        ], $token, $secret);
    }

    /** @param  array{account_id?: ?string, access_token?: ?string, app_secret?: ?string}  $data */
    public function instagram(array $data, User $actor): MessagingChannel
    {
        $row = self::ensure('instagram');
        [$token, $secret] = $this->secrets($row, $data);

        try {
            $account = $this->client->instagramAccount($token);
        } catch (MetaApiException $exception) {
            throw ValidationException::withMessages(['access_token' => __('Meta rejected these details: :error', ['error' => $exception->getMessage()])]);
        }

        $accountId = $account['user_id'] ?? ($data['account_id'] ?? null);

        if (! $accountId) {
            throw ValidationException::withMessages(['account_id' => __('Enter the Instagram account id.')]);
        }

        $this->assertNotTaken('instagram', $accountId);

        return $this->save($row, $actor, [
            'external_id' => $accountId,
            'business_account_id' => null,
            'display_name' => $account['name'],
            'display_handle' => $account['username'] ? '@'.$account['username'] : null,
        ], $token, $secret);
    }

    /** @return array{0: string, 1: string} */
    private function secrets(MessagingChannel $row, array $data): array
    {
        $token = filled($data['access_token'] ?? null) ? trim($data['access_token']) : $row->credential('access_token');
        $secret = filled($data['app_secret'] ?? null) ? trim($data['app_secret']) : $row->credential('app_secret');

        $errors = array_filter([
            'access_token' => $token ? null : __('Enter the access token.'),
            'app_secret' => $secret ? null : __('Enter the app secret.'),
        ]);

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$token, $secret];
    }

    private function assertNotTaken(string $channel, string $externalId): void
    {
        $taken = MessagingChannel::withoutTenantScope()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('tenant_id', '!=', $this->context->id())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([$channel === 'whatsapp' ? 'phone_number_id' : 'account_id' => __('This account is already connected to another AutoWave business.')]);
        }
    }

    /** @param  array<string, ?string>  $attributes */
    private function save(MessagingChannel $row, User $actor, array $attributes, string $token, string $secret): MessagingChannel
    {
        $row->forceFill([
            ...$attributes,
            'status' => ChannelStatus::Connected,
            'credentials' => [...($row->credentials ?? []), 'access_token' => $token, 'app_secret' => $secret],
            'last_error' => null,
            'connected_by_user_id' => $actor->id,
            'connected_at' => now(),
        ])->save();

        $this->audit->log('messaging.channel_connected', $row, ['channel' => $row->channel, 'external_id' => $row->external_id]);

        return $row;
    }
}
