<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Customer search for pickers in forms: name, email, phone or the last digits of the phone. */
final class CustomerLookup
{
    public const LIMIT = 10;

    /** @return list<array{id: int, name: string, phone: ?string, email: ?string}> */
    public static function search(string $search): array
    {
        $search = Str::limit(trim($search), 100, '');

        if (mb_strlen($search) < 2) {
            return [];
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = preg_replace('/\D/', '', $search);

        return Customer::query()
            ->where(function (Builder $query) use ($like, $digits) {
                $query->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('phone', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $query->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            })
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'phone', 'email'])
            ->map(fn (Customer $customer) => $customer->only(['id', 'name', 'phone', 'email']))
            ->all();
    }
}
