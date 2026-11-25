<?php

namespace App\Support;

// Text and whole paise: 42.50 is 4250.
class Rupees
{
    public static function parse(mixed $text): ?int
    {
        return Digits::parse($text, 8, 2);
    }

    public static function show(int $paise): string
    {
        return Digits::show($paise, 2);
    }

    // With the sign and Indian commas, for messages.
    public static function format(int $paise): string
    {
        [$whole, $part] = explode('.', self::show(abs($paise)));

        return ($paise < 0 ? '−' : '') . '₹' . preg_replace('/(?<=\d)(?=(\d\d)*\d{3}$)/', ',', $whole) . ".$part";
    }
}
