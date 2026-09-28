<?php

namespace App\Domain\Messaging\Support;

use App\Support\Phone;

/** The key that identifies a contact on a channel: "+919876543210" on WhatsApp, the scoped user id on Instagram. */
class ContactHandle
{
    public static function for(string $channel, ?string $recipient): ?string
    {
        $recipient = trim((string) $recipient);

        if ($recipient === '') {
            return null;
        }

        return match ($channel) {
            'whatsapp' => Phone::normalize($recipient),
            'instagram' => preg_match('/^\d{5,64}$/', $recipient) === 1 ? $recipient : null,
            default => null,
        };
    }

    /** A WhatsApp id (wa_id) is the full international number without the "+". */
    public static function fromWaId(string $waId): ?string
    {
        $digits = preg_replace('/\D+/', '', $waId) ?? '';

        return $digits === '' ? null : Phone::normalize('+'.$digits);
    }

    /** Digits only, as the WhatsApp Cloud API expects in `to`. */
    public static function whatsappDigits(string $handle): string
    {
        return preg_replace('/\D+/', '', $handle) ?? '';
    }
}
