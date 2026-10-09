<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

class ExchangeRateResolver
{
    public function forAmount(string $amount): ExchangeRate
    {
        $plan = ExchangeRate::query()
            ->where('is_active', true)
            ->where('minimum_amount', '<=', $amount)
            ->where(fn ($query) => $query->whereNull('maximum_amount')->orWhere('maximum_amount', '>=', $amount))
            ->orderByDesc('minimum_amount')
            ->first();

        if (! $plan) {
            throw ValidationException::withMessages([
                'amount' => 'No active rate slab matches this amount. Please contact support.',
            ]);
        }

        return $plan;
    }

    public function inrAmount(string $amount, string $rate): string
    {
        $inrAmount = Decimal::multiplyToCents($amount, $rate);
        if (Decimal::compare($inrAmount, '999999999999999999.99', 2) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'The calculated INR amount exceeds the supported accounting limit.',
            ]);
        }

        return $inrAmount;
    }
}
