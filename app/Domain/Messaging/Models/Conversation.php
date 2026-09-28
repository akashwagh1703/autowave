<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Domain\Tenant\Models\TenantUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thread with one contact on one channel. `contact_handle` is the normalised phone for WhatsApp
 * and the Instagram-scoped user id for Instagram.
 */
#[Fillable([
    'tenant_id', 'channel', 'contact_handle', 'contact_name', 'customer_id', 'lead_id', 'assigned_tenant_user_id',
    'status', 'unread_count', 'last_message_at', 'last_message_preview', 'last_message_direction',
    'last_inbound_at', 'opted_out_at',
])]
class Conversation extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'open',
        'unread_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'opted_out_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assigned_tenant_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function isOptedOut(): bool
    {
        return $this->opted_out_at !== null;
    }

    /** The name to show: the linked customer or lead, the provider profile name, or the handle. */
    public function displayName(): string
    {
        return $this->customer?->name ?? $this->lead?->name ?? $this->contact_name ?? $this->contact_handle;
    }
}
