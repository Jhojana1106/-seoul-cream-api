<?php

namespace App\Support;

final class Quantity
{
    public static function of(mixed $value, int $scale = 3): string
    {
        if ($value === null || $value === '') {
            $value = '0';
        }

        $number = is_string($value) ? trim($value) : (string) $value;

        if (function_exists('bcadd')) {
            return bcadd($number, '0', $scale);
        }

        return number_format(round((float) $number, $scale), $scale, '.', '');
    }

    public static function money(mixed $value): string
    {
        return self::of($value, 2);
    }

    public static function add(mixed $a, mixed $b, int $scale = 3): string
    {
        if (function_exists('bcadd')) {
            return bcadd(self::of($a, $scale), self::of($b, $scale), $scale);
        }

        return number_format(round((float) $a + (float) $b, $scale), $scale, '.', '');
    }

    public static function sub(mixed $a, mixed $b, int $scale = 3): string
    {
        if (function_exists('bcsub')) {
            return bcsub(self::of($a, $scale), self::of($b, $scale), $scale);
        }

        return number_format(round((float) $a - (float) $b, $scale), $scale, '.', '');
    }

    public static function mul(mixed $a, mixed $b, int $scale = 3): string
    {
        if (function_exists('bcmul')) {
            return bcmul(self::of($a, $scale), self::of($b, $scale), $scale);
        }

        return number_format(round((float) $a * (float) $b, $scale), $scale, '.', '');
    }

    public static function div(mixed $a, mixed $b, int $scale = 3): string
    {
        if (self::eq($b, 0, $scale)) {
            throw new \InvalidArgumentException('División por cero.');
        }

        if (function_exists('bcdiv')) {
            return bcdiv(self::of($a, $scale + 2), self::of($b, $scale + 2), $scale);
        }

        return number_format(round((float) $a / (float) $b, $scale), $scale, '.', '');
    }

    public static function cmp(mixed $a, mixed $b, int $scale = 3): int
    {
        if (function_exists('bccomp')) {
            return bccomp(self::of($a, $scale), self::of($b, $scale), $scale);
        }

        return round((float) $a, $scale) <=> round((float) $b, $scale);
    }

    public static function gt(mixed $a, mixed $b, int $scale = 3): bool
    {
        return self::cmp($a, $b, $scale) === 1;
    }

    public static function gte(mixed $a, mixed $b, int $scale = 3): bool
    {
        return self::cmp($a, $b, $scale) >= 0;
    }

    public static function lt(mixed $a, mixed $b, int $scale = 3): bool
    {
        return self::cmp($a, $b, $scale) === -1;
    }

    public static function lte(mixed $a, mixed $b, int $scale = 3): bool
    {
        return self::cmp($a, $b, $scale) <= 0;
    }

    public static function eq(mixed $a, mixed $b, int $scale = 3): bool
    {
        return self::cmp($a, $b, $scale) === 0;
    }

    public static function min(mixed $a, mixed $b, int $scale = 3): string
    {
        return self::lte($a, $b, $scale) ? self::of($a, $scale) : self::of($b, $scale);
    }
}
