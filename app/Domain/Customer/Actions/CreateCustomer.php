<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Activity\Actions\RecordActivity;
use App\Domain\Customer\Events\CustomerCreated;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\CustomerTags;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a customer in the current tenant. One live customer per normalised phone number.
 */
class CreateCustomer
{
    public function __construct(private readonly RecordActivity $recordActivity) {}

    /**
     * @param  array{name: string, phone?: ?string, email?: ?string, city?: ?string, address?: ?string, tags?: ?list<string>, notes?: ?string}  $data
     * @param  array<string, mixed>  $activityMetadata  context for the `created` timeline entry
     */
    public function handle(array $data, ?User $actor = null, array $activityMetadata = []): Customer
    {
        $this->ensurePhoneIsFree($data['phone'] ?? null);

        return DB::transaction(function () use ($data, $actor, $activityMetadata) {
            $customer = Customer::query()->create([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'tags' => CustomerTags::normalize($data['tags'] ?? []),
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->recordActivity->handle('created', customer: $customer, actor: $actor, metadata: $activityMetadata);

            CustomerCreated::dispatch($customer);

            return $customer;
        });
    }

    public static function duplicateOf(?string $phone, ?int $ignoreId = null): ?Customer
    {
        $normalized = Phone::normalize($phone);

        if (! $normalized) {
            return null;
        }

        return Customer::query()
            ->where('phone_normalized', $normalized)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->first();
    }

    private function ensurePhoneIsFree(?string $phone): void
    {
        if (self::duplicateOf($phone)) {
            throw ValidationException::withMessages(['phone' => 'A customer with this phone number already exists.']);
        }
    }
}
