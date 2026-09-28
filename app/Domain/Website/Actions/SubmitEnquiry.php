<?php

namespace App\Domain\Website\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Lead\Actions\CreateLead;
use App\Domain\Lead\Models\Lead;
use App\Domain\Website\Events\WebsiteEnquiryReceived;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Turns a website enquiry into a lead with the source "Website". When the visitor already has an
 * open lead (same phone number), the enquiry is added to that lead's timeline instead of creating
 * a duplicate. Either way a `website_enquiry` entry holds the message.
 */
class SubmitEnquiry
{
    public function __construct(
        private readonly CreateLead $createLead,
        private readonly RecordActivity $recordActivity,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function handle(array $input): Lead
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'interest' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:'.config('website.enquiry.max_message')],
        ], [
            'phone.regex' => __('Enter a valid phone number.'),
        ], [
            'interest' => __('interested in'),
        ])->validate();

        $data = array_map(fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : null, $data)
            + ['email' => null, 'interest' => null, 'message' => null];

        if (Phone::normalize($data['phone']) === null) {
            throw ValidationException::withMessages(['phone' => __('Enter a valid phone number.')]);
        }

        return DB::transaction(function () use ($data) {
            $existing = CreateLead::openDuplicateOf(Phone::normalize($data['phone']));

            $lead = $existing ?? $this->createLead->handle([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'interest' => $data['interest'],
                'source' => 'website',
            ]);

            $this->recordActivity->handle('website_enquiry', lead: $lead, body: $data['message'], metadata: array_filter([
                'name' => $existing ? $data['name'] : null,
                'email' => $existing ? $data['email'] : null,
                'interest' => $data['interest'],
            ]));

            WebsiteEnquiryReceived::dispatch($lead, $existing === null);

            return $lead;
        });
    }
}
