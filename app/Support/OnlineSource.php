<?php

namespace App\Support;

/**
 * Where a customer booked, reserved or ordered by themselves: the public website or a WhatsApp chat with
 * the assistant (ADR-021). Both follow the business's online settings (notice, auto-confirm, fees);
 * anything else is entered by the team (`manual`).
 */
final class OnlineSource
{
    public const ALL = ['website', 'whatsapp'];

    public static function is(mixed $source): bool
    {
        return in_array($source, self::ALL, true);
    }

    /** The source to store: an online source as given, otherwise `manual`. */
    public static function resolve(mixed $source): string
    {
        return self::is($source) ? $source : 'manual';
    }
}
