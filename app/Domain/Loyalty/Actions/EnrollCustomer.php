<?php

namespace App\Domain\Loyalty\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Loyalty\Models\LoyaltyAccount;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Finds or creates a customer and opens a loyalty account (idempotent). */
class EnrollCustomer
{
    public function __construct(
        private readonly CreateCustomer $createCustomer,
        private readonly LoyaltySettings $settings,
        private readonly RecordActivity $recordActivity,
    ) {}

    /**
     * @param  array{name?: ?string, phone: string, date_of_birth?: ?string}  $data
     */
    public function handle(array $data, ?User $actor = null, string $via = 'manual'): LoyaltyAccount
    {
        if (! $this->settings->enabled()) {
            throw ValidationException::withMessages(['loyalty' => __('Loyalty rewards are switched off.')]);
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone === '') {
            throw ValidationException::withMessages(['phone' => __('Enter a phone number.')]);
        }

        return DB::transaction(function () use ($data, $actor, $via, $phone) {
            $customer = CreateCustomer::duplicateOf($phone);
            $dob = filled($data['date_of_birth'] ?? null) ? $data['date_of_birth'] : null;

            if (! $customer) {
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    throw ValidationException::withMessages(['name' => __('Enter your name.')]);
                }

                $customer = $this->createCustomer->handle([
                    'name' => $name,
                    'phone' => $phone,
                    'date_of_birth' => $dob,
                ], $actor, ['via' => 'loyalty']);
            } elseif ($dob && ! $customer->date_of_birth) {
                $customer->forceFill(['date_of_birth' => $dob])->save();
            }

            return $this->forCustomer($customer, $actor, $via);
        });
    }

    public function forCustomer(Customer $customer, ?User $actor = null, string $via = 'manual'): LoyaltyAccount
    {
        $existing = LoyaltyAccount::query()->where('customer_id', $customer->id)->first();

        if ($existing) {
            return $existing;
        }

        $account = LoyaltyAccount::query()->create([
            'customer_id' => $customer->id,
            'public_token' => (string) Str::ulid(),
            'stamps_balance' => 0,
            'lifetime_stamps' => 0,
            'rewards_redeemed' => 0,
            'joined_at' => now(),
        ]);

        $this->recordActivity->handle('loyalty_joined', customer: $customer, actor: $actor, metadata: [
            'via' => $via,
            'account_id' => $account->id,
        ]);

        return $account;
    }
}
