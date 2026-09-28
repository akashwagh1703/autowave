<?php

namespace App\Domain\Activity\Actions;

use App\Domain\Activity\Models\Activity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A team member logs a note, call, message or meeting on a lead or customer timeline.
 * On a lead, contact types update last_contacted_at, and the next follow-up can be set or cleared.
 */
class LogActivity
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    /**
     * @param  bool  $updateFollowUp  when true, $nextFollowupAt replaces the lead's follow-up (null clears it)
     */
    public function handle(
        Lead|Customer $subject,
        string $type,
        ?string $body,
        ?User $actor = null,
        ?DateTimeInterface $occurredAt = null,
        bool $updateFollowUp = false,
        ?DateTimeInterface $nextFollowupAt = null,
    ): Activity {
        if (! array_key_exists($type, config('crm.loggable_activities'))) {
            throw ValidationException::withMessages(['type' => 'Choose a valid activity type.']);
        }

        $occurredAt ??= now();

        return DB::transaction(function () use ($subject, $type, $body, $actor, $occurredAt, $updateFollowUp, $nextFollowupAt) {
            if ($subject instanceof Lead) {
                if (in_array($type, config('crm.contact_activities'), true)
                    && ($subject->last_contacted_at === null || $subject->last_contacted_at->lt($occurredAt))) {
                    $subject->last_contacted_at = $occurredAt;
                }

                if ($updateFollowUp) {
                    $subject->next_followup_at = $nextFollowupAt;
                }

                $subject->save();
            }

            return $this->recordActivity->handle(
                $type,
                lead: $subject instanceof Lead ? $subject : null,
                customer: $subject instanceof Customer ? $subject : null,
                actor: $actor,
                body: $body,
                metadata: $updateFollowUp && $subject instanceof Lead ? ['next_followup_at' => $nextFollowupAt?->format(DATE_ATOM)] : [],
                occurredAt: $occurredAt,
            );
        });
    }
}
