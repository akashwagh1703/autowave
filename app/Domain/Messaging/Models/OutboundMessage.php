<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Domain\Tenant\Models\TenantUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message sent (or being sent) through MessagingService. `simulated` messages went to a provider
 * that does not deliver anything (the `log` provider).
 */
#[Fillable([
    'tenant_id', 'channel', 'provider', 'simulated', 'recipient', 'recipient_name', 'subject', 'body', 'status',
    'idempotency_key', 'lead_id', 'customer_id', 'tenant_user_id', 'automation_run_id', 'attempts',
    'provider_message_id', 'error', 'queued_at', 'sent_at', 'failed_at',
])]
class OutboundMessage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'simulated' => 'boolean',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'tenant_user_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    /** "+91 ••••• •3210" / "a•••@example.com" — enough to recognise, without exposing the full contact. */
    public function maskedRecipient(): string
    {
        $recipient = $this->recipient;

        if (str_contains($recipient, '@')) {
            [$local, $domain] = explode('@', $recipient, 2);

            return mb_substr($local, 0, 1).'•••@'.$domain;
        }

        $digits = preg_replace('/\D+/', '', $recipient) ?? '';

        return strlen($digits) > 4 ? str_repeat('•', strlen($digits) - 4).substr($digits, -4) : $recipient;
    }
}
