<?php

namespace App\Http\Controllers\Marketing;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Actions\SubmitEnquiry;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Website\EnquiryController;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Book a demo" on the marketing site: the request becomes a website lead in the AutoWave Internal
 * tenant, so sales follows it up in AutoWave's own CRM like any business would.
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

        $internal = Tenant::query()->where('is_internal', true)->orderBy('id')->first();

        if ($internal === null) {
            Log::error('marketing.demo_without_internal_tenant');

            throw ValidationException::withMessages(['name' => __('We could not save your request right now. Please contact us on WhatsApp or email.')]);
        }

        $context->run($internal, fn () => $submit->handle([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'interest' => __('Demo').($data['industry'] ? ': '.$data['industry'] : ''),
            'message' => implode("\n", array_filter([
                __('Business: :name', ['name' => $data['business_name']]),
                $data['city'] ? __('City: :city', ['city' => $data['city']]) : null,
                filled($data['message']) ? "\n".trim($data['message']) : null,
            ])),
        ]));

        return back()->with('demo_requested', true);
    }
}
