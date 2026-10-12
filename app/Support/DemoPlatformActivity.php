<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class DemoPlatformActivity
{
    private const INR_PER_USDT = 110;

    private const BASE_USDT_DEPOSITS = [
        4187, 3814, 8062, 14633, 7425, 3118, 603, 12687, 7481,
    ];

    private const PHONE_PREFIXES = [
        '96', '91', '88', '90', '93', '95', '87', '98', '97',
    ];

    private const PHONE_ENDINGS = [
        3461, 8754, 2319, 5467, 7925, 3186, 6542, 9317, 2458,
    ];

    /**
     * These illustrative figures are generated from the Kolkata calendar date,
     * independently of all customer and transaction records.
     *
     * @return array{
     *     active_clients: int,
     *     deposit_usdt: int,
     *     deposit_display: string,
     *     inr_display: string,
     *     transactions: list<array{mobile: string, deposit_usdt: int, inr: int}>
     * }
     */
    public static function forDate(?CarbonImmutable $date = null): array
    {
        $today = ($date ?? CarbonImmutable::now('Asia/Kolkata'))
            ->setTimezone('Asia/Kolkata')
            ->startOfDay();
        $anchor = CarbonImmutable::parse('2026-01-01', 'Asia/Kolkata');
        $dayOffset = max(0, intdiv($today->getTimestamp() - $anchor->getTimestamp(), 86400));

        $activeClients = 12500 + ($dayOffset * 7) + ($dayOffset % 17);
        $depositUsdt = 2450000 + ($dayOffset * 3250) + ($dayOffset % 97);
        $depositInr = $depositUsdt * self::INR_PER_USDT;

        $transactions = [];
        foreach (self::BASE_USDT_DEPOSITS as $index => $baseDeposit) {
            $deposit = $baseDeposit + ($dayOffset * (($index % 5) + 1)) + ($dayOffset % 7);
            $lastFour = (self::PHONE_ENDINGS[$index] + ($dayOffset * 17) + ($index * 101)) % 10000;
            $transactions[] = [
                'mobile' => '+91 '.self::PHONE_PREFIXES[$index].'****'.str_pad((string) $lastFour, 4, '0', STR_PAD_LEFT),
                'deposit_usdt' => $deposit,
                'inr' => $deposit * self::INR_PER_USDT,
            ];
        }

        return [
            'active_clients' => $activeClients,
            'deposit_usdt' => $depositUsdt,
            'deposit_display' => '$'.self::formatTenths($depositUsdt, 100000).'M',
            'inr_display' => self::formatTenths($depositInr, 1000000).'Cr',
            'transactions' => $transactions,
        ];
    }

    private static function formatTenths(int $amount, int $tenthsUnit): string
    {
        $tenths = intdiv($amount + intdiv($tenthsUnit, 2), $tenthsUnit);

        return intdiv($tenths, 10).'.'.($tenths % 10);
    }

    public static function formatInr(int $amount): string
    {
        $digits = (string) $amount;
        if (strlen($digits) <= 3) {
            return $digits;
        }

        $lastThree = substr($digits, -3);
        $prefix = substr($digits, 0, -3);

        return (preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $prefix) ?? $prefix).','.$lastThree;
    }
}
