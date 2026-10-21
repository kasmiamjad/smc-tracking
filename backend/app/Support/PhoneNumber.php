<?php

namespace App\Support;

/**
 * Phone numbers are stored in E.164 (+966501234567). The mobile app is
 * responsible for prefixing the country code; we only strip formatting.
 */
final class PhoneNumber
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/[\s\-().]/', '', $input);
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) ? $digits : null;
    }
}
