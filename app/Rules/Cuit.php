<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Valida CUIT/CUIL argentino (11 dígitos + dígito verificador). Acepta con o sin guiones. */
class Cuit implements ValidationRule
{
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        return strlen($digits) === 11
            ? substr($digits, 0, 2).'-'.substr($digits, 2, 8).'-'.substr($digits, 10)
            : $digits;
    }

    public static function isValid(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) !== 11) {
            return false;
        }

        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += (int) $digits[$i] * $w;
        }

        $check = 11 - ($sum % 11);
        $check = match ($check) {
            11 => 0,
            10 => 9,
            default => $check,
        };

        return $check === (int) $digits[10];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('El CUIT/CUIL no es válido.');
        }
    }
}
