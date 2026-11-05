<?php

namespace App\Domain\Loyalty\Actions;

use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyStamp;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Redeems one reward by subtracting stamps_required from the balance. */
class RedeemReward
{
    public function __construct(
        private readonly LoyaltySettings $settings,
        private readonly AddStamps $addStamps,
    ) {}

    public function handle(LoyaltyAccount $account, ?User $actor = null): LoyaltyStamp
    {
        if (! $this->settings->enabled()) {
            throw ValidationException::withMessages(['loyalty' => __('Loyalty rewards are switched off.')]);
        }

        $required = $this->settings->all()['stamps_required'];
        $label = $this->settings->all()['reward_label'];

        if ($account->stamps_balance < $required) {
            throw ValidationException::withMessages([
                'stamps' => __('Need :count more stamps for :reward.', [
                    'count' => $required - $account->stamps_balance,
                    'reward' => $label,
                ]),
            ]);
        }

        $stamp = $this->addStamps->handle(
            $account,
            -$required,
            'redeem',
            null,
            note: $label,
            actor: $actor,
        );

        return $stamp ?? throw ValidationException::withMessages(['stamps' => __('Could not redeem the reward.')]);
    }
}
