<?php

namespace App\Http\Requests\Booking;

use App\Http\Requests\Onboarding\StoreBusinessRequest;
use App\Support\TenantTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Book an appointment. `starts_at` is a tenant-local date-time. The customer is either an
 * existing customer_id or inline details (reused by phone). Ids are checked against the current
 * tenant by BookAppointment.
 */
class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('customer')) && $this->filled('customer.name')) {
            $this->merge(['customer' => [...$this->input('customer'), 'name' => Str::squish((string) $this->input('customer.name'))]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'required_without:customer.name'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['nullable', 'required_without:customer_id', 'string', 'min:2', 'max:150'],
            'customer.phone' => ['nullable', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'customer.email' => ['nullable', 'string', 'email', 'max:255'],
            'booking_resource_id' => ['required', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'required_without:service_id', 'integer', 'min:'.config('booking.duration.min'), 'max:'.config('booking.duration.max')],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:pending,confirmed'],
            'allow_outside_hours' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required_without' => __('Choose a customer or enter their details.'),
            'customer.name.required_without' => __('Enter the customer\'s name.'),
            'customer.phone.regex' => __('Enter a valid phone number.'),
            'duration_minutes.required_without' => __('Enter a duration or choose a service.'),
        ];
    }

    /** Validated data ready for BookAppointment. */
    public function bookingData(): array
    {
        $data = $this->validated();

        return [
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'customer' => empty($data['customer_id']) ? ($data['customer'] ?? null) : null,
            'booking_resource_id' => (int) $data['booking_resource_id'],
            'service_id' => isset($data['service_id']) ? (int) $data['service_id'] : null,
            'starts_at' => TenantTime::parse($data['starts_at']),
            'duration_minutes' => isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
            'price' => $data['price'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? null,
            'allow_outside_hours' => (bool) ($data['allow_outside_hours'] ?? false),
        ];
    }
}
