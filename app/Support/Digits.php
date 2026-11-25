<?php

namespace App\Support;

class Digits
{
    public static function parse(mixed $text, int $whole, int $decimals): ?int
    {
        if (is_int($text)) {
            $text = (string) $text;
        }
        if (! is_string($text) || ! preg_match("/^(\d{1,$whole})(?:\.(\d{1,$decimals}))?$/D", trim($text), $parts)) {
            return null;
        }

        return (int) ($parts[1] . str_pad($parts[2] ?? '', $decimals, '0'));
    }

    public static function show(int $number, int $decimals): string
    {
        $digits = str_pad((string) abs($number), $decimals + 1, '0', STR_PAD_LEFT);

        return ($number < 0 ? '-' : '') . substr($digits, 0, -$decimals) . '.' . substr($digits, -$decimals);
    }
}
