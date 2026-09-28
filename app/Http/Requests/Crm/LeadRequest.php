<?php

namespace App\Http\Requests\Crm;

use App\Http\Requests\Onboarding\StoreBusinessRequest;
use App\Support\TenantTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Create and edit lead details. Stage, source and assignee ids are checked against the current
 * tenant by the domain actions (CreateLead / UpdateLead / AssignLead).
 * Permissions are enforced by route middleware.
 */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => Str::squish((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['nullable', 'required_without:email', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'interest' => ['nullable', 'string', 'max:255'],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'lead_source_id' => ['nullable', 'integer'],
            'next_followup_at' => ['nullable', 'date'],
            ...($creating ? [
                'lead_stage_id' => ['nullable', 'integer'],
                'assigned_tenant_user_id' => [$this->user()?->can('leads.assign') ? 'nullable' : 'prohibited', 'integer'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter a valid phone number.'),
            'phone.required_without' => __('Enter a phone number or an email address.'),
            'assigned_tenant_user_id.prohibited' => __('You do not have permission to assign leads.'),
        ];
    }

    /** Validated data ready for CreateLead / UpdateLead. */
    public function leadData(): array
    {
        $data = $this->validated();

        if (array_key_exists('next_followup_at', $data)) {
            $data['next_followup_at'] = TenantTime::parse($data['next_followup_at']);
        }

        foreach (['lead_stage_id', 'lead_source_id', 'assigned_tenant_user_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }

        return $data;
    }
}
