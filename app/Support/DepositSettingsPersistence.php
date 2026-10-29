<?php

namespace App\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class DepositSettingsPersistence
{
    public const COLUMNS = [
        'network_name',
        'blockchain',
        'token_contract_address',
        'receiving_wallet_address',
        'logo_path',
        'minimum_amount',
        'is_enabled',
    ];

    public static function storageReady(): bool
    {
        return Schema::hasColumns('deposit_settings', self::COLUMNS);
    }

    public static function matches(string $attribute, mixed $actual, mixed $expected): bool
    {
        if ($attribute === 'is_enabled') {
            return (bool) $actual === (bool) $expected;
        }

        if ($attribute === 'updated_by_user_id') {
            if ($actual === null || $expected === null) {
                return $actual === null && $expected === null;
            }

            return (int) $actual === (int) $expected;
        }

        if ($attribute === 'minimum_amount') {
            return Decimal::compare((string) $actual, (string) $expected, 8) === 0;
        }

        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        return (string) $actual === (string) $expected;
    }

    public static function adminMessage(Throwable $exception): string
    {
        if ($exception instanceof ModelNotFoundException) {
            return 'Deposit settings could not be saved because the settings record could not be reloaded. Please try again.';
        }

        $message = $exception->getMessage();

        if (in_array($message, [
            'The payment QR image could not be stored.',
            'The network logo could not be stored.',
        ], true)) {
            return $message;
        }

        if (preg_match('/\AThe saved deposit settings could not be verified for ([a-z_]+)\.\z/', $message, $matches) === 1) {
            $labels = [
                'recipient_name' => 'recipient name',
                'instructions' => 'instructions',
                'network_name' => 'network',
                'blockchain' => 'blockchain',
                'token_contract_address' => 'token contract',
                'receiving_wallet_address' => 'receiving wallet',
                'minimum_amount' => 'minimum amount',
                'is_enabled' => 'deposit status',
                'updated_by_user_id' => 'updating administrator',
                'qr_path' => 'QR image',
                'logo_path' => 'network logo',
            ];

            if (isset($labels[$matches[1]])) {
                return 'Deposit settings could not be saved because the '.$labels[$matches[1]].' could not be confirmed. Please try again.';
            }
        }

        if (str_contains($message, '42S22') || str_contains($message, 'Unknown column')) {
            return 'Deposit settings could not be saved because the database is missing required columns. Apply pending migrations, then try again.';
        }

        if (str_contains($message, '42S02') || str_contains($message, 'Base table or view not found')) {
            return 'Deposit settings could not be saved because the deposit settings table is missing. Apply pending migrations, then try again.';
        }

        if (str_contains($message, '1031') || str_contains($message, "doesn't have this option")) {
            return 'Deposit settings could not be saved because the table does not support row locking. Convert deposit_settings to InnoDB, then try again.';
        }

        if (str_contains($message, '22001') || str_contains($message, 'Data too long')) {
            return 'Deposit settings could not be saved because a value is longer than the database allows.';
        }

        if (str_contains($message, '23000') || str_contains($message, 'Integrity constraint violation')) {
            return 'Deposit settings could not be saved because the database rejected the change. Please try again.';
        }

        return 'Deposit settings could not be saved. Please try again.';
    }

    public static function missingStorageMessage(): string
    {
        return 'Deposit settings could not be saved because the database is missing required columns. Apply pending migrations, then try again.';
    }
}
