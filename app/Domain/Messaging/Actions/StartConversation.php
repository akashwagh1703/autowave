<?php

namespace App\Domain\Messaging\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\ConversationRecorder;
use App\Domain\Messaging\Support\ContactHandle;
use Illuminate\Validation\ValidationException;

/** Opens (or finds) the WhatsApp conversation with a customer or lead, from their page. */
class StartConversation
{
    public function __construct(private readonly ConversationRecorder $recorder) {}

    public function handle(?Customer $customer = null, ?Lead $lead = null): Conversation
    {
        $contact = $customer ?? $lead;
        $handle = ContactHandle::for('whatsapp', $contact?->phone);

        if (! $handle) {
            throw ValidationException::withMessages(['phone' => __('Add a valid phone number first.')]);
        }

        $conversation = $this->recorder->findOrCreate('whatsapp', $handle, [
            'contact_name' => $contact->name,
            'customer_id' => $customer?->id ?? $lead?->customer_id,
            'lead_id' => $lead?->id,
        ]);

        $conversation->forceFill([
            'customer_id' => $conversation->customer_id ?? $customer?->id ?? $lead?->customer_id,
            'lead_id' => $conversation->lead_id ?? $lead?->id,
        ]);

        if ($conversation->isDirty()) {
            $conversation->save();
        }

        return $conversation;
    }
}
