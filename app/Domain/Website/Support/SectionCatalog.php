<?php

namespace App\Domain\Website\Support;

use App\Domain\Tenant\Support\TenantContext;

/**
 * The website section registry (config/website.php `sections`) as seen by the current tenant:
 * which section types and fields its engines and modules allow, and each section's configuration
 * with defaults filled in.
 */
class SectionCatalog
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return config('website.sections');
    }

    public static function definition(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function isRemovable(string $type): bool
    {
        return (self::definition($type)['removable'] ?? true) === true;
    }

    public static function position(string $type): ?string
    {
        return self::definition($type)['position'] ?? null;
    }

    /** Engine / module requirements of a section type or field. */
    public function allows(array $definition): bool
    {
        return (! isset($definition['engine']) || $this->context->hasEngine($definition['engine']))
            && (! isset($definition['module']) || $this->context->hasModule($definition['module']));
    }

    public function isAvailable(string $type): bool
    {
        $definition = self::definition($type);

        return $definition !== null && $this->allows($definition);
    }

    /** @return array<string, array<string, mixed>> the fields this tenant can edit */
    public function fields(string $type): array
    {
        return array_filter(self::definition($type)['fields'] ?? [], fn (array $field) => $this->allows($field));
    }

    /**
     * Stored configuration with defaults for missing or empty values. Keys the tenant cannot
     * use (e.g. the enquiry form without the leads module) keep their stored value but are left
     * out, so they never reach the site.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    public function resolve(string $type, ?array $stored): array
    {
        $resolved = [];

        foreach ($this->fields($type) as $key => $field) {
            $value = $stored[$key] ?? null;
            $resolved[$key] = self::isEmpty($value) ? ($field['default'] ?? ($field['type'] === 'list' ? [] : null)) : $value;
        }

        return $resolved;
    }

    /**
     * Sections sorted for display: the header first, the footer last, the rest by sort_order.
     *
     * @template T of object{type: string, sort_order: int}
     *
     * @param  iterable<T>  $sections
     * @return list<T>
     */
    public static function sorted(iterable $sections): array
    {
        $list = is_array($sections) ? $sections : iterator_to_array($sections, false);

        usort($list, fn ($a, $b) => [self::rank($a->type), $a->sort_order, $a->id ?? 0] <=> [self::rank($b->type), $b->sort_order, $b->id ?? 0]);

        return $list;
    }

    private static function rank(string $type): int
    {
        return match (self::position($type)) {
            'first' => 0,
            'last' => 2,
            default => 1,
        };
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
