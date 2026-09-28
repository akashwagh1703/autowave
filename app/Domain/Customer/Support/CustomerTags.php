<?php

namespace App\Domain\Customer\Support;

use Illuminate\Support\Str;

final class CustomerTags
{
    public const MAX_TAGS = 10;

    public const MAX_LENGTH = 30;

    /**
     * Trimmed, de-duplicated (case-insensitive) and capped.
     *
     * @param  iterable<mixed>  $tags
     * @return list<string>
     */
    public static function normalize(iterable $tags): array
    {
        return collect($tags)
            ->filter(fn ($tag) => is_string($tag))
            ->map(fn (string $tag) => Str::limit(Str::squish($tag), self::MAX_LENGTH, ''))
            ->filter()
            ->unique(fn (string $tag) => Str::lower($tag))
            ->take(self::MAX_TAGS)
            ->values()
            ->all();
    }
}
