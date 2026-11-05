<?php

namespace App\Domain\Loyalty\Support;

use App\Domain\Loyalty\Models\LoyaltyAccount;

/** Props for the app and public loyalty UIs. */
class LoyaltyPresenter
{
    /**
     * @return array{
     *     account_id: int,
     *     token: string,
     *     stamps_balance: int,
     *     stamps_required: int,
     *     rewards_available: int,
     *     rewards_redeemed: int,
     *     lifetime_stamps: int,
     *     reward_label: string,
     *     card_url: ?string,
     *     joined_at: string
     * }
     */
    public static function account(LoyaltyAccount $account, LoyaltySettings $settings): array
    {
        $config = $settings->all();

        return [
            'account_id' => $account->id,
            'token' => $account->public_token,
            'stamps_balance' => $account->stamps_balance,
            'stamps_required' => $config['stamps_required'],
            'rewards_available' => $account->rewardsAvailable($config['stamps_required']),
            'rewards_redeemed' => $account->rewards_redeemed,
            'lifetime_stamps' => $account->lifetime_stamps,
            'reward_label' => $config['reward_label'],
            'card_url' => LoyaltyLinks::cardUrl($account),
            'joined_at' => $account->joined_at->toIso8601String(),
        ];
    }
}
