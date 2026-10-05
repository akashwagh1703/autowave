<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\Billing\Support\BillingRecipients;
use App\Domain\Marketing\Models\DemoRequest;
use App\Domain\Marketing\Notifications\DemoRequested;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Actions\SubmitEnquiry;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Website\EnquiryController;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Book a demo" on the marketing site. The request is saved for Super Admin → Demo requests, emailed to the
 * platform admins, and copied as a website lead into the AutoWave Internal CRM when that tenant exists.
 */
class DemoRequestController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, SubmitEnquiry $submit): RedirectResponse
    {
        if (filled($request->input(EnquiryController::HONEYPOT))) {
            Log::info('marketing.demo_honeypot');

            return back()->with('demo_requested', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:'.StoreBusinessRequest::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'industry' => ['nullable', 'string', Rule::in(collect(config('marketing.industries'))->pluck('name')->push('Other')->all())],
            'city' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], ['phone.regex' => __('Enter a valid phone number.')]) + ['email' => null, 'industry' => null, 'city' => null, 'message' => null];

        $phone = Phone::normalize($data['phone']) ?? throw ValidationException::withMessages(['phone' => __('Enter a valid phone number.')]);
        $message = filled($data['message']) ? trim($data['message']) : null;

        $demo = DemoRequest::query()->create([
            'name' => trim($data['name']),
            'phone' => $phone,
            'email' => $data['email'],
            'business_name' => trim($data['business_name']),
            'industry' => $data['industry'],
            'city' => filled($data['city']) ? trim($data['city']) : null,
            'message' => $message,
        ]);

        $internal = Tenant::query()->where('is_internal', true)->orderBy('id')->first();

        if ($internal === null) {
            Log::warning('marketing.demo_without_internal_tenant', ['demo_request_id' => $demo->id]);
        } else {
            $lead = $context->run($internal, fn () => $submit->handle([
                'name' => $demo->name,
                'phone' => $data['phone'],
                'email' => $demo->email,
                'interest' => __('Demo').($demo->industry ? ': '.$demo->industry : ''),
                'message' => implode("\n", array_filter([
                    __('Business: :name', ['name' => $demo->business_name]),
                    $demo->city ? __('City: :city', ['city' => $demo->city]) : null,
                    $message ? "\n".$message : null,
                ])),
            ]));
            $demo->update(['lead_id' => $lead->id]);
        }

        Notification::send(BillingRecipients::platformAdmins(), new DemoRequested($demo->id));

        return back()->with('demo_requested', true);
    }
}
