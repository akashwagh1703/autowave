<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\CustomerTags;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCustomer
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    /**
     * @param  array{name?: string, phone?: ?string, email?: ?string, city?: ?string, address?: ?string, tags?: ?list<string>, notes?: ?string}  $data
     */
    public function handle(Customer $customer, array $data, ?User $actor = null): Customer
    {
        if (array_key_exists('phone', $data) && CreateCustomer::duplicateOf($data['phone'], $customer->id)) {
            throw ValidationException::withMessages(['phone' => 'Another customer already has this phone number.']);
        }

        if (array_key_exists('tags', $data)) {
            $data['tags'] = CustomerTags::normalize($data['tags'] ?? []);
        }

        return DB::transaction(function () use ($customer, $data, $actor) {
            $customer->fill(array_intersect_key($data, array_flip(['name', 'phone', 'email', 'city', 'address', 'tags', 'notes'])));
            $changed = array_values(array_diff(array_keys($customer->getDirty()), ['phone_normalized']));

            if ($changed === []) {
                return $customer;
            }

            $customer->save();
            $this->recordActivity->handle('updated', customer: $customer, actor: $actor, metadata: ['changed' => $changed]);

            return $customer;
        });
    }
}
