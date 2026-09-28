<?php

namespace App\Domain\Lead\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Events\LeadUpdated;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadSource;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a lead's details. Stage and assignment have their own actions.
 */
class UpdateLead
{
    public const EDITABLE = ['name', 'phone', 'email', 'interest', 'estimated_value', 'lead_source_id', 'next_followup_at'];

    public function __construct(private readonly RecordActivity $recordActivity) {}

    /**
     * @param  array<string, mixed>  $data  keys from self::EDITABLE
     * @param  array<string, mixed>  $metadata  extra timeline metadata, e.g. ['via' => 'ai']
     */
    public function handle(Lead $lead, array $data, ?User $actor = null, array $metadata = []): Lead
    {
        $data = array_intersect_key($data, array_flip(self::EDITABLE));

        if (array_key_exists('lead_source_id', $data) && $data['lead_source_id'] !== null && $data['lead_source_id'] !== $lead->lead_source_id
            && ! LeadSource::query()->active()->whereKey($data['lead_source_id'])->exists()) {
            throw ValidationException::withMessages(['lead_source_id' => 'Choose an active lead source.']);
        }

        $phone = array_key_exists('phone', $data) ? Phone::normalize($data['phone']) : $lead->phone_normalized;

        if ($phone !== $lead->phone_normalized && $lead->isOpen() && CreateLead::openDuplicateOf($phone, $lead->id)) {
            throw ValidationException::withMessages(['phone' => 'Another open lead already has this phone number.']);
        }

        return DB::transaction(function () use ($lead, $data, $actor, $phone, $metadata) {
            $lead->fill($data);
            $changed = array_keys($lead->getDirty());

            if ($changed === []) {
                return $lead;
            }

            if ($lead->customer_id === null && $phone && in_array('phone', $changed, true)) {
                $lead->customer_id = Customer::query()->where('phone_normalized', $phone)->value('id');
            }

            $lead->save();

            $this->recordActivity->handle('updated', lead: $lead, actor: $actor, metadata: ['changed' => $changed, ...$metadata]);

            LeadUpdated::dispatch($lead, $changed);

            return $lead;
        });
    }
}
