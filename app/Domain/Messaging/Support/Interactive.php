<?php

namespace App\Domain\Messaging\Support;

use App\Domain\Media\Models\Media;

/**
 * Builds the provider-neutral `interactive` payload of an outbound message: reply buttons, a list, or
 * an image with the body as its caption. Texts are cut to WhatsApp's limits here, so a long service
 * name never makes Meta refuse the whole message. Providers without interactive messages send the
 * body followed by the options as numbered lines (fallbackText()).
 */
class Interactive
{
    public const MAX_BUTTONS = 3;

    public const MAX_ROWS = 10;

    /** Image types WhatsApp shows inline. */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png'];

    /** Body text of an interactive message (a plain text message allows 4096). */
    public const BODY_MAX = 1024;

    /**
     * @param  list<array{id: string, title: string}>  $buttons
     * @return array<string, mixed>
     */
    public static function buttons(array $buttons, ?string $footer = null, ?Media $image = null): array
    {
        return array_filter([
            'kind' => 'buttons',
            'header_image' => self::image($image),
            'footer' => self::cut($footer, 60),
            'buttons' => array_map(
                fn (array $button) => ['id' => self::cut($button['id'], 200), 'title' => self::cut($button['title'], 20)],
                array_slice($buttons, 0, self::MAX_BUTTONS),
            ),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  list<array{id: string, title: string, description?: ?string}>  $rows
     * @return array<string, mixed>
     */
    public static function list(string $button, array $rows, ?string $header = null, ?string $footer = null): array
    {
        return array_filter([
            'kind' => 'list',
            'header' => self::cut($header, 60),
            'footer' => self::cut($footer, 60),
            'button' => self::cut($button, 20),
            'rows' => array_map(fn (array $row) => array_filter([
                'id' => self::cut($row['id'], 200),
                'title' => self::cut($row['title'], 24),
                'description' => self::cut($row['description'] ?? null, 72),
            ], fn ($value) => $value !== null), array_slice($rows, 0, self::MAX_ROWS)),
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed>|null an image sent on its own, with the body as caption */
    public static function photo(?Media $image): ?array
    {
        $image = self::image($image);

        return $image ? ['kind' => 'image', 'image' => $image] : null;
    }

    /**
     * The options a person sees, in order: button and row titles.
     *
     * @param  array<string, mixed>|null  $interactive
     * @return list<array{id: string, title: string}>
     */
    public static function options(?array $interactive): array
    {
        $items = match ($interactive['kind'] ?? null) {
            'buttons' => $interactive['buttons'] ?? [],
            'list' => $interactive['rows'] ?? [],
            default => [],
        };

        return array_values(array_map(fn (array $item) => ['id' => (string) $item['id'], 'title' => (string) $item['title']], $items));
    }

    /**
     * The body with the options as numbered lines, for channels that cannot show buttons. Contacts can
     * answer with the number.
     *
     * @param  array<string, mixed>|null  $interactive
     */
    public static function fallbackText(string $body, ?array $interactive): string
    {
        $lines = [];

        foreach (self::options($interactive) as $index => $option) {
            $lines[] = ($index + 1).'. '.$option['title'];
        }

        return $lines === [] ? $body : trim($body."\n\n".implode("\n", $lines));
    }

    /** @return array{disk: string, path: string, mime: string, url: string}|null */
    private static function image(?Media $media): ?array
    {
        if (! $media || ! in_array($media->mime_type, self::IMAGE_TYPES, true)) {
            return null;
        }

        return ['disk' => $media->disk, 'path' => $media->path, 'mime' => $media->mime_type, 'url' => $media->url()];
    }

    private static function cut(?string $value, int $limit): ?string
    {
        $value = $value === null ? null : trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > $limit ? rtrim(mb_substr($value, 0, $limit - 1)).'…' : $value;
    }
}
