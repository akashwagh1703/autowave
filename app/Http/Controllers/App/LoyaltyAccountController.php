<?php

namespace App\Http\Controllers\App;

use App\Domain\Customer\Models\Customer;
use App\Domain\Loyalty\Actions\AddStamps;
use App\Domain\Loyalty\Actions\EnrollCustomer;
use App\Domain\Loyalty\Actions\RedeemReward;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Staff actions on a customer's loyalty card. */
class LoyaltyAccountController extends Controller
{
    public function enroll(Customer $customer, EnrollCustomer $enroll): RedirectResponse
    {
        $enroll->forCustomer($customer, request()->user(), via: 'staff');

        return back()->with('success', __('Customer enrolled in loyalty.'));
    }

    public function stamp(Request $request, Customer $customer, LoyaltySettings $settings, EnrollCustomer $enroll, AddStamps $addStamps): RedirectResponse
    {
        abort_unless($settings->enabled(), 404);

        $data = $request->validate([
            'delta' => ['required', 'integer', 'min:1', 'max:20'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $account = $enroll->forCustomer($customer, $request->user(), via: 'staff');
        $addStamps->handle($account, (int) $data['delta'], 'manual', null, $data['note'] ?? null, $request->user());

        return back()->with('success', __('Stamp added.'));
    }

    public function redeem(Customer $customer, LoyaltySettings $settings, RedeemReward $redeem): RedirectResponse
    {
        abort_unless($settings->enabled(), 404);

        $account = LoyaltyAccount::query()->where('customer_id', $customer->id)->first()
            ?? throw ValidationException::withMessages(['loyalty' => __('This customer is not on the loyalty card yet.')]);

        $redeem->handle($account, request()->user());

        return back()->with('success', __('Reward redeemed: :label.', ['label' => $settings->all()['reward_label']]));
    }
}
