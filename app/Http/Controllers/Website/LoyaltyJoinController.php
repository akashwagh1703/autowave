<?php

namespace App\Http\Controllers\Website;

use App\Domain\Loyalty\Actions\EnrollCustomer;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Support\LoyaltyLinks;
use App\Domain\Loyalty\Support\LoyaltyPresenter;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/** Public loyalty join page (QR target) and the customer's card after joining. */
class LoyaltyJoinController extends Controller
{
    public const HONEYPOT = 'company_website';

    public function show(LoyaltySettings $settings, TenantContext $context): Response
    {
        abort_unless($settings->enabled(), 404);

        $config = $settings->all();

        return Inertia::render('website/LoyaltyJoin', [
            'business' => ['name' => $context->tenant()->name],
            'heading' => $config['join_heading'],
            'blurb' => $config['join_blurb'],
            'stamps_required' => $config['stamps_required'],
            'reward_label' => $config['reward_label'],
            'collect_birthday' => $config['collect_birthday'],
        ]);
    }

    public function store(Request $request, LoyaltySettings $settings, EnrollCustomer $enroll): RedirectResponse
    {
        abort_unless($settings->enabled(), 404);

        if (filled($request->input(self::HONEYPOT))) {
            Log::info('loyalty.join_honeypot', ['tenant_id' => app(TenantContext::class)->id()]);

            return back()->with('success', true);
        }

        $collectBirthday = $settings->all()['collect_birthday'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'date_of_birth' => [$collectBirthday ? 'required' : 'nullable', 'date', 'before:today', 'after:1900-01-01'],
        ]);

        $account = $enroll->handle($data, via: 'qr');
        $cardUrl = LoyaltyLinks::cardUrl($account);

        return redirect($cardUrl ?? url('/loyalty/card/'.$account->public_token))
            ->with('success', __('You are on the rewards card.'));
    }

    public function card(string $token, LoyaltySettings $settings, TenantContext $context): Response
    {
        abort_unless($settings->enabled(), 404);

        $account = LoyaltyAccount::query()
            ->where('public_token', $token)
            ->with('customer')
            ->firstOrFail();

        return Inertia::render('website/LoyaltyCard', [
            'business' => ['name' => $context->tenant()->name],
            'customer' => [
                'name' => $account->customer?->name,
            ],
            'loyalty' => LoyaltyPresenter::account($account, $settings),
        ]);
    }
}
