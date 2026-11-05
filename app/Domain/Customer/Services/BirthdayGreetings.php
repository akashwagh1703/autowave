<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Events\CustomerBirthday;
use App\Domain\Customer\Models\Customer;
use App\Domain\Loyalty\Actions\AddStamps;
use App\Domain\Loyalty\Actions\EnrollCustomer;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Support\TenantTime;

/**
 * Once a day (tenant timezone): greet customers whose birthday matches today (or the 1st of their
 * birth month). Claims `birthday_greeted_on` so each year fires once. Optionally grants enough
 * loyalty stamps for one free reward.
 */
class BirthdayGreetings
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LoyaltySettings $loyalty,
        private readonly EnrollCustomer $enroll,
        private readonly AddStamps $addStamps,
    ) {}

    /** @return array{greeted: int, rewards: int} */
    public function run(): array
    {
        if (! $this->context->hasModule('customers')) {
            return ['greeted' => 0, 'rewards' => 0];
        }

        $today = TenantTime::now()->startOfDay();
        $mode = $this->loyalty->enabled() ? $this->loyalty->all()['birthday_mode'] : 'day';
        $grantReward = $this->loyalty->enabled() && $this->loyalty->all()['birthday_grant_reward'];

        if ($mode === 'month' && (int) $today->day !== 1) {
            return ['greeted' => 0, 'rewards' => 0];
        }

        $query = Customer::query()
            ->whereNotNull('date_of_birth')
            ->where(function ($query) use ($today) {
                $query->whereNull('birthday_greeted_on')
                    ->orWhereYear('birthday_greeted_on', '<', $today->year);
            })
            ->whereMonth('date_of_birth', $today->month);

        if ($mode === 'day') {
            $query->whereDay('date_of_birth', $today->day);
        }

        $greeted = 0;
        $rewards = 0;

        foreach ($query->orderBy('id')->limit(1000)->pluck('id') as $id) {
            $claimed = Customer::query()
                ->whereKey($id)
                ->where(function ($query) use ($today) {
                    $query->whereNull('birthday_greeted_on')
                        ->orWhereYear('birthday_greeted_on', '<', $today->year);
                })
                ->update(['birthday_greeted_on' => $today->toDateString()]);

            if ($claimed !== 1) {
                continue;
            }

            $customer = Customer::query()->findOrFail($id);
            CustomerBirthday::dispatch($customer, $mode);
            $greeted++;

            if ($grantReward && $this->grantReward($customer, $today->year)) {
                $rewards++;
            }
        }

        return ['greeted' => $greeted, 'rewards' => $rewards];
    }

    private function grantReward(Customer $customer, int $year): bool
    {
        $settings = $this->loyalty->all();
        $account = $this->enroll->forCustomer($customer, via: 'birthday')->fresh();
        $needed = max(0, $settings['stamps_required'] - $account->stamps_balance);

        if ($needed < 1) {
            return false;
        }

        return $this->addStamps->handle(
            $account,
            $needed,
            'birthday',
            $year,
            note: $settings['birthday_reward_label'] ?: $settings['reward_label'],
        ) !== null;
    }
}
