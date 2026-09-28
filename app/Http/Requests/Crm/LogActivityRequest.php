<?php

namespace App\Http\Requests\Crm;

use App\Support\TenantTime;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LogActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(config('crm.loggable_activities')))],
            'body' => [Rule::requiredIf($this->input('type') === 'note'), 'nullable', 'string', 'max:5000'],
            'occurred_at' => ['bail', 'nullable', 'date', function (string $attribute, mixed $value, Closure $fail) {
                if (TenantTime::parse($value)?->isAfter(now()->addMinutes(5))) {
                    $fail(__('An activity cannot be logged in the future.'));
                }
            }],
            'update_followup' => ['boolean'],
            'next_followup_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => __('Write the note first.'),
        ];
    }
}
