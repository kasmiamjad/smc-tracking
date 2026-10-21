<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Exactly 4 digits, and not one of the PINs attackers try first
 * (all-same digits like 0000, or straight runs like 1234 / 9876).
 */
class SecurePin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\d{4}$/', $value)) {
            $fail('The :attribute must be exactly 4 digits.');

            return;
        }

        if (self::isTrivial($value)) {
            $fail('The :attribute is too easy to guess. Avoid repeated or sequential digits.');
        }
    }

    public static function isTrivial(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        return str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
    }

    public static function generate(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::isTrivial($pin));

        return $pin;
    }
}
