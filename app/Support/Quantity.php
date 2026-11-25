<?php

namespace App\Support;

// Text and whole thousandths, with string functions only: 2.5 is 2500.
class Quantity
{
    public static function parse(mixed $text): ?int
    {
        return Digits::parse($text, 7, 3);
    }

    public static function show(int $quantity): string
    {
        return rtrim(rtrim(Digits::show($quantity, 3), '0'), '.');
    }
}
