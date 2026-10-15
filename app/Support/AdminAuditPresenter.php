<?php

namespace App\Support;

use App\Models\ExchangeRate;

final class AdminAuditPresenter
{
    public function eventTitle(string $event): string
    {
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '.', $event) ?? $event, '.'));
        $normalized = preg_replace('/^admin\./', '', $normalized) ?? $normalized;

        return str($normalized)->replace('.', ' ')->title()->toString();
    }

    public function shouldDisplay(string $key, mixed $value): bool
    {
        if (preg_match('/password|secret|token|otp|credential|bank|account.number|ifsc|email|phone|ip.address|proof.path/i', $key)) {
            return false;
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $nestedKey => $nestedValue) {
                if ($this->shouldDisplay((string) $nestedKey, $nestedValue)) {
                    return true;
                }
            }

            return false;
        }

        return is_scalar($value);
    }

    public function label(string|int $key): string
    {
        $key = (string) $key;

        return match ($key) {
            'customer_id' => 'Customer ID',
            'transaction_reference' => 'Transaction Reference',
            'request_reference' => 'Request Reference',
            'reference' => 'Reference',
            'usdt_amount' => 'USDT Amount',
            'inr_amount' => 'INR Amount',
            'rejection_reason' => 'Rejection Reason',
            'usdt_inr_rate_snapshot' => 'Exchange Rate',
            'exchange_rate', 'rate' => 'Exchange Rate',
            'rate_plan' => 'Rate Plan',
            'payout_method' => 'Payout Method',
            'ledger_credit' => 'Ledger Credit',
            'is_active' => 'Active',
            default => is_numeric($key)
                ? 'Item '.((int) $key + 1)
                : str($key)->replace(['_', '-'], ' ')->title()->toString(),
        };
    }

    public function value(string|int $key, mixed $value, string $event): string
    {
        if (is_bool($value)) {
            return $value ? 'Enabled' : 'Disabled';
        }

        $value = is_scalar($value) ? (string) $value : '';
        $key = (string) $key;
        $normalizedEvent = strtolower($event);
        $value = str_replace(
            ['BNB Smart Chain mainnet (BEP20)', 'BNB Smart Chain mainnet', 'BNB Smart Chain'],
            ['BEP20 mainnet', 'BEP20 mainnet', 'BEP20'],
            $value
        );

        if (! is_numeric($value)) {
            if (in_array($key, ['status', 'payout_method', 'action'], true)) {
                return str($value)->replace(['_', '-'], ' ')->title()->toString();
            }

            return $value;
        }

        if ($key === 'usdt_amount') {
            return Money::formatUsdt($value).' USDT';
        }

        if ($key === 'inr_amount' || in_array($key, ['usdt_inr_rate_snapshot', 'exchange_rate', 'rate'], true)) {
            if (in_array($key, ['usdt_inr_rate_snapshot', 'exchange_rate', 'rate'], true)) {
                return '₹'.ExchangeRate::formatDecimal($value).' / USDT';
            }

            return Money::formatInr($value);
        }

        if ($key === 'amount') {
            if (str_contains($normalizedEvent, 'deposit')) {
                return Money::formatUsd($value);
            }

            if (str_contains($normalizedEvent, 'withdrawal')
                || str_contains($normalizedEvent, 'referral')
                || str_contains($normalizedEvent, 'commission')) {
                return Money::formatInr($value);
            }
        }

        return $value;
    }
}
