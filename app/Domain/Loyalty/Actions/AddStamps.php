<?php

namespace App\Domain\Loyalty\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Models\LoyaltyStamp;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Credits stamps on a loyalty account (idempotent when source + source_id are set). */
class AddStamps
{
    public function __construct(
        private readonly LoyaltySettings $settings,
        private readonly RecordActivity $recordActivity,
    ) {}

    public function handle(
        LoyaltyAccount $account,
        int $delta,
        string $source,
        ?int $sourceId = null,
        ?string $note = null,
        ?User $actor = null,
    ): ?LoyaltyStamp {
        if ($delta === 0) {
            return null;
        }

        if (! $this->settings->enabled() && $source !== 'redeem') {
            throw ValidationException::withMessages(['loyalty' => __('Loyalty rewards are switched off.')]);
        }

        try {
            return DB::transaction(function () use ($account, $delta, $source, $sourceId, $note, $actor) {
                $account = LoyaltyAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

                if ($delta < 0 && $account->stamps_balance + $delta < 0) {
                    throw ValidationException::withMessages(['stamps' => __('Not enough stamps to redeem.')]);
                }

                $stamp = LoyaltyStamp::query()->create([
                    'loyalty_account_id' => $account->id,
                    'delta' => $delta,
                    'source' => $source,
                    'source_id' => $sourceId,
                    'note' => $note,
                    'created_by_user_id' => $actor?->id,
                ]);

                $account->forceFill([
                    'stamps_balance' => $account->stamps_balance + $delta,
                    'lifetime_stamps' => $delta > 0 ? $account->lifetime_stamps + $delta : $account->lifetime_stamps,
                    'rewards_redeemed' => $source === 'redeem' ? $account->rewards_redeemed + 1 : $account->rewards_redeemed,
                ])->save();

                $customer = $account->customer;
                if ($customer && ! $customer->trashed()) {
                    $settings = $this->settings->all();
                    $this->recordActivity->handle(
                        $source === 'redeem' ? 'loyalty_reward_redeemed' : 'loyalty_stamp_earned',
                        customer: $customer,
                        actor: $actor,
                        body: $source === 'redeem' ? $settings['reward_label'] : null,
                        metadata: [
                            'delta' => $delta,
                            'balance' => $account->stamps_balance,
                            'required' => $settings['stamps_required'],
                            'source' => $source,
                            'source_id' => $sourceId,
                        ],
                    );
                }

                return $stamp;
            });
        } catch (UniqueConstraintViolationException) {
            return LoyaltyStamp::query()
                ->where('source', $source)
                ->where('source_id', $sourceId)
                ->first();
        }
    }
}
