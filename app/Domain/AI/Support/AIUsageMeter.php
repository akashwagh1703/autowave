<?php

namespace App\Domain\AI\Support;

use App\Domain\AI\Models\AIUsage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Tokens used per business per calendar month (UTC) against its cap. The cap is
 * config('ai.limits.monthly_tokens') unless a platform admin set `ai_quota.monthly_tokens` for the
 * business (a tenant setting no business screen can write).
 */
class AIUsageMeter
{
    public const QUOTA_KEY = 'ai_quota';

    private const CACHE_SECONDS = 60;

    public function cap(Tenant $tenant): int
    {
        $quota = TenantSetting::withoutTenantScope()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', self::QUOTA_KEY)
            ->value('value');

        return is_array($quota) && isset($quota['monthly_tokens']) && is_numeric($quota['monthly_tokens'])
            ? max(0, (int) $quota['monthly_tokens'])
            : (int) config('ai.limits.monthly_tokens');
    }

    /** Whether the cap is the platform default (no override for this business). */
    public function hasCustomCap(Tenant $tenant): bool
    {
        return TenantSetting::withoutTenantScope()->where('tenant_id', $tenant->getKey())->where('key', self::QUOTA_KEY)->exists();
    }

    public function setCap(Tenant $tenant, ?int $tokens): void
    {
        $query = TenantSetting::withoutTenantScope()->where('tenant_id', $tenant->getKey())->where('key', self::QUOTA_KEY);

        if ($tokens === null) {
            $query->delete();

            return;
        }

        $setting = $query->first() ?? new TenantSetting(['tenant_id' => $tenant->getKey(), 'key' => self::QUOTA_KEY]);
        $setting->value = ['monthly_tokens' => max(0, $tokens)];
        $setting->save();
    }

    public function used(Tenant $tenant): int
    {
        return (int) Cache::remember($this->cacheKey($tenant), self::CACHE_SECONDS, fn () => (int) AIUsage::withoutTenantScope()
            ->where('tenant_id', $tenant->getKey())
            ->where('created_at', '>=', self::monthStart())
            ->sum('total_tokens'));
    }

    public function exceeded(Tenant $tenant): bool
    {
        return $this->used($tenant) >= $this->cap($tenant);
    }

    /** @param  array<string, mixed>  $attributes */
    public function record(Tenant $tenant, array $attributes): AIUsage
    {
        $usage = new AIUsage($attributes);
        $usage->tenant_id = $tenant->getKey();
        $usage->save();

        Cache::forget($this->cacheKey($tenant));

        return $usage;
    }

    /** @return array{used: int, cap: int, requests: int, percent: int, resets_at: string} */
    public function summary(Tenant $tenant): array
    {
        $used = $this->used($tenant);
        $cap = $this->cap($tenant);

        return [
            'used' => $used,
            'cap' => $cap,
            'requests' => AIUsage::withoutTenantScope()->where('tenant_id', $tenant->getKey())->where('created_at', '>=', self::monthStart())->count(),
            'percent' => $cap > 0 ? min(100, (int) floor($used * 100 / $cap)) : 100,
            'resets_at' => self::monthStart()->addMonth()->toIso8601String(),
        ];
    }

    public static function monthStart(): Carbon
    {
        return Carbon::now('UTC')->startOfMonth();
    }

    private function cacheKey(Tenant $tenant): string
    {
        return 'ai:used:'.$tenant->getKey().':'.self::monthStart()->format('Ym');
    }
}
