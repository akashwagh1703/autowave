<?php

namespace App\Domain\Files\Support;

use App\Domain\Billing\Support\Entitlements;
use App\Domain\Files\Models\Attachment;
use App\Domain\Media\Models\Media;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use Illuminate\Validation\ValidationException;

/**
 * Storage used by a business (images, documents and videos together) against its allowance. The allowance
 * is `storage_quota.mb` when a platform admin set it for the business (a tenant setting no business screen
 * can write), otherwise the plan's `storage_mb`, otherwise config('files.quota_mb').
 */
class StorageAllowance
{
    public const QUOTA_KEY = 'storage_quota';

    private const MB = 1024 * 1024;

    public function __construct(private readonly Entitlements $entitlements) {}

    public function capMb(Tenant $tenant): int
    {
        $quota = $this->setting($tenant)->value('value');

        return is_array($quota) && isset($quota['mb']) && is_numeric($quota['mb'])
            ? max(0, (int) $quota['mb'])
            : $this->defaultMb($tenant);
    }

    /** The allowance without an override: the plan's, or the platform default. */
    public function defaultMb(Tenant $tenant): int
    {
        return (int) ($this->entitlements->limit($tenant, 'storage_mb') ?? config('files.quota_mb'));
    }

    public function hasCustomCap(Tenant $tenant): bool
    {
        return $this->setting($tenant)->exists();
    }

    public function setCap(Tenant $tenant, ?int $mb): void
    {
        if ($mb === null) {
            $this->setting($tenant)->delete();

            return;
        }

        $setting = $this->setting($tenant)->first() ?? new TenantSetting(['tenant_id' => $tenant->getKey(), 'key' => self::QUOTA_KEY]);
        $setting->value = ['mb' => max(0, $mb)];
        $setting->save();
    }

    public function usedBytes(Tenant $tenant): int
    {
        // Platform accounting across the tenant's own rows only.
        return (int) Media::withoutTenantScope()->where('tenant_id', $tenant->getKey())->sum('size_bytes')
            + (int) Attachment::withoutTenantScope()->where('tenant_id', $tenant->getKey())->sum('size_bytes');
    }

    /** @throws ValidationException when the file would not fit */
    public function ensureRoomFor(Tenant $tenant, int $bytes, string $field = 'file'): void
    {
        $cap = $this->capMb($tenant) * self::MB;
        $used = $this->usedBytes($tenant);

        if ($used + $bytes > $cap) {
            throw ValidationException::withMessages([$field => __('Your storage is full (:used MB of :cap MB used). Delete some files, or ask AutoWave support for more space.', [
                'used' => number_format($used / self::MB, 1),
                'cap' => number_format($cap / self::MB),
            ])]);
        }
    }

    /** @return array{used_bytes: int, cap_bytes: int, percent: int} */
    public function summary(Tenant $tenant): array
    {
        $used = $this->usedBytes($tenant);
        $cap = $this->capMb($tenant) * self::MB;

        return [
            'used_bytes' => $used,
            'cap_bytes' => $cap,
            'percent' => $cap > 0 ? min(100, (int) floor($used * 100 / $cap)) : 100,
        ];
    }

    private function setting(Tenant $tenant)
    {
        return TenantSetting::withoutTenantScope()->where('tenant_id', $tenant->getKey())->where('key', self::QUOTA_KEY);
    }
}
