<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class WalletTransactionPinService
{
    public function assertValid(User $user, string $pin, ?string $ipAddress): void
    {
        if (! preg_match('/^\d{4}$/', $pin)) {
            throw ValidationException::withMessages([
                'wallet_transaction_pin' => 'Enter your four-digit Wallet Transaction PIN.',
            ]);
        }

        $accountKey = 'wallet-pin:account:'.$user->id;
        $ipKey = 'wallet-pin:ip:'.hash('sha256', (string) $ipAddress);

        if (RateLimiter::tooManyAttempts($accountKey, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            throw ValidationException::withMessages([
                'wallet_transaction_pin' => 'Too many incorrect Wallet Transaction PIN attempts. Please try again later.',
            ]);
        }

        if (! $user->hasWalletTransactionPin() || ! Hash::check($pin, $user->wallet_transaction_password_hash)) {
            RateLimiter::hit($accountKey, 900);
            RateLimiter::hit($ipKey, 900);

            throw ValidationException::withMessages([
                'wallet_transaction_pin' => 'Incorrect Wallet Transaction PIN.',
            ]);
        }

        RateLimiter::clear($accountKey);
        RateLimiter::clear($ipKey);
    }
}
