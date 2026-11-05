<?php

namespace App\Domain\Loyalty\Support;

use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;

/** Tenant loyalty preferences: the `loyalty` tenant setting over config/loyalty.php defaults. */
class LoyaltySettings
{
    public const KEY = 'loyalty';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{
     *     enabled: bool,
     *     stamps_required: int,
     *     earn_per: string,
     *     product_ids: list<int>,
     *     earn_appointments: bool,
     *     reward_label: string,
     *     join_heading: string,
     *     join_blurb: string,
     *     collect_birthday: bool,
     *     birthday_mode: string,
     *     birthday_grant_reward: bool,
     *     birthday_reward_label: string
     * }
     */
    public function all(): array
    {
        $stored = $this->stored();
        $defaults = config('loyalty');
        $value = fn (string $key) => array_key_exists($key, $stored) ? $stored[$key] : $defaults[$key];

        $earnPer = (string) $value('earn_per');
        if (! in_array($earnPer, ['order', 'item'], true)) {
            $earnPer = (string) $defaults['earn_per'];
        }

        $birthdayMode = (string) $value('birthday_mode');
        if (! in_array($birthdayMode, ['day', 'month'], true)) {
            $birthdayMode = (string) $defaults['birthday_mode'];
        }

        $productIds = $value('product_ids');
        $productIds = is_array($productIds)
            ? array_values(array_unique(array_map('intval', array_filter($productIds, fn ($id) => (int) $id > 0))))
            : [];

        return [
            'enabled' => (bool) $value('enabled'),
            'stamps_required' => max(2, min(50, (int) $value('stamps_required'))),
            'earn_per' => $earnPer,
            'product_ids' => $productIds,
            'earn_appointments' => (bool) $value('earn_appointments'),
            'reward_label' => filled($value('reward_label')) ? (string) $value('reward_label') : (string) $defaults['reward_label'],
            'join_heading' => filled($value('join_heading')) ? (string) $value('join_heading') : (string) $defaults['join_heading'],
            'join_blurb' => filled($value('join_blurb')) ? (string) $value('join_blurb') : (string) $defaults['join_blurb'],
            'collect_birthday' => (bool) $value('collect_birthday'),
            'birthday_mode' => $birthdayMode,
            'birthday_grant_reward' => (bool) $value('birthday_grant_reward'),
            'birthday_reward_label' => filled($value('birthday_reward_label')) ? (string) $value('birthday_reward_label') : (string) $defaults['birthday_reward_label'],
        ];
    }

    public function enabled(): bool
    {
        return $this->context->hasModule('loyalty') && $this->all()['enabled'];
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): void
    {
        TenantSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => [...$this->stored(), ...$values]]);
        $this->context->tenant()->unsetRelation('settings');
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $value = $this->context->setting(self::KEY, []);

        return is_array($value) ? $value : [];
    }
}
