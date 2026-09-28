<?php

namespace App\Support;

/**
 * Phone normalisation for matching (not validation or formatting for display).
 *
 * "+91 98765-43210", "098765 43210" and "9876543210" all become "+919876543210" with the
 * default country code 91 (config('crm.default_country_code')).
 */
class Phone
{
    public static function normalize(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $international = str_starts_with($phone, '+') || str_starts_with($phone, '00');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($international) {
            $digits = str_starts_with($phone, '00') ? substr($digits, 2) : $digits;
        } else {
            $digits = ltrim($digits, '0');

            if (strlen($digits) < 7) {
                return null;
            }

            if (strlen($digits) <= 10) {
                $digits = config('crm.default_country_code', '91').$digits;
            }
        }

        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }
}
