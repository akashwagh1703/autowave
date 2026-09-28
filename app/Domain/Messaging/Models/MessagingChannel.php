<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Messaging\Enums\ChannelStatus;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A tenant's connected WhatsApp number or Instagram account (ADR-018). `credentials` is encrypted at
 * rest and hidden from serialisation; read it only through credential().
 *
 * @property array{access_token?: string, app_secret?: string, verify_token?: string}|null $credentials
 */
#[Fillable([
    'tenant_id', 'channel', 'status', 'external_id', 'business_account_id', 'display_name', 'display_handle',
    'credentials', 'webhook_key', 'last_error', 'connected_by_user_id', 'connected_at', 'last_webhook_at',
])]
#[Hidden(['credentials', 'webhook_key'])]
class MessagingChannel extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(function (MessagingChannel $channel): void {
            $channel->webhook_key ??= Str::random(40);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ChannelStatus::class,
            'credentials' => 'encrypted:array',
            'connected_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function isConnected(): bool
    {
        return $this->status === ChannelStatus::Connected && filled($this->credential('access_token'));
    }

    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The token Meta echoes on webhook verification; created once and kept across reconnects. */
    public function verifyToken(): string
    {
        return $this->credential('verify_token') ?? '';
    }
}
