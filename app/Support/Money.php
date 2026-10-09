<?php

namespace App\Support;

final class Money
{
    public static function normalizeUsd(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 2, '0');
    }

    public static function formatUsd(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, 2, '0');

        return '$'.$whole.'.'.$fraction;
    }

    public static function formatUsdt(string $amount, int $precision = 8): string
    {
        $precision = min(8, max(6, $precision));
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;
        $fraction = substr(str_pad($fraction, $precision, '0'), 0, $precision);
        while (strlen($fraction) > 6 && str_ends_with($fraction, '0')) {
            $fraction = substr($fraction, 0, -1);
        }

        return $whole.'.'.$fraction;
    }

    public static function formatInr(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;

        return '₹'.$whole.'.'.str_pad($fraction, 2, '0');
    }
}
