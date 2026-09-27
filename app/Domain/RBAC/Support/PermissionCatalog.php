<?php

namespace App\Domain\RBAC\Support;

use Illuminate\Support\Str;

/**
 * Reads the permission catalogue from config/rbac.php.
 */
class PermissionCatalog
{
    /** @return array<string, string> key => description */
    public static function all(): array
    {
        $permissions = [];

        foreach (config('rbac.permissions', []) as $group => $actions) {
            foreach ($actions as $action => $description) {
                $permissions["{$group}.{$action}"] = $description;
            }
        }

        return $permissions;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    /**
     * Expand patterns such as "leads.*" into concrete permission keys.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        $keys = static::keys();

        return collect($patterns)
            ->flatMap(fn (string $pattern) => array_filter($keys, fn (string $key) => Str::is($pattern, $key)))
            ->unique()
            ->values()
            ->all();
    }
}
