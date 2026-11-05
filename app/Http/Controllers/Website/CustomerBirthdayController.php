<?php

namespace App\Http\Controllers\Website;

use App\Domain\Customer\Models\Customer;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Signed public page for a customer to save their birthday after an order. */
class CustomerBirthdayController extends Controller
{
    public function show(Customer $customer, TenantContext $context, LoyaltySettings $loyalty): Response
    {
        abort_unless($loyalty->enabled() && $loyalty->all()['collect_birthday'], 404);

        $profile = $context->setting('business_profile', []);
        $profile = is_array($profile) ? $profile : [];

        return Inertia::render('website/CustomerBirthday', [
            'business' => [
                'name' => $context->tenant()->name,
                'phone' => $profile['phone'] ?? null,
            ],
            'customer' => [
                'name' => $customer->name,
                'has_birthday' => (bool) $customer->date_of_birth,
            ],
        ]);
    }

    public function store(Request $request, Customer $customer, LoyaltySettings $loyalty): RedirectResponse
    {
        abort_unless($loyalty->enabled() && $loyalty->all()['collect_birthday'], 404);

        if ($customer->date_of_birth) {
            return back()->with('success', __('Thanks — we already have your birthday.'));
        }

        $validated = $request->validate([
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1900-01-01'],
        ]);

        $customer->forceFill(['date_of_birth' => $validated['date_of_birth']])->save();

        return back()->with('success', __('Thanks — we saved your birthday.'));
    }
}
