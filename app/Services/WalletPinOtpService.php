<?php

namespace App\Services;

use App\Exceptions\WalletOtpRateLimitException;
use App\Mail\WalletTransactionPinOtp;
use App\Models\User;
use App\Models\WalletPinOtp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

class WalletPinOtpService
{
    public const PURPOSES = ['wallet_password_set', 'wallet_password_change', 'wallet_password_reset'];
    private const SEND_LIMIT = 5;
    private const SEND_WINDOW_SECONDS = 600;

    public function send(User $user, string $purpose): ?string
    {
        $this->assertPurposeAllowed($user, $purpose);
        $key = $this->sendLimiterKey($user, $purpose);
        if (RateLimiter::tooManyAttempts($key, self::SEND_LIMIT)) {
            throw new WalletOtpRateLimitException(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, self::SEND_WINDOW_SECONDS);

        $code = (string) random_int(100000, 999999);
        $otp = DB::transaction(function () use ($user, $purpose, $code): WalletPinOtp {
            WalletPinOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return WalletPinOtp::create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'otp_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
                'attempts' => 0,
            ]);
        });

        try {
            Mail::to($user->email)->send(new WalletTransactionPinOtp($code));
        } catch (Throwable $exception) {
            $otp->delete();
            Log::warning('Wallet transaction PIN OTP delivery failed.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            throw ValidationException::withMessages([
                'wallet_pin_otp' => "We couldn't send the verification code. Please try again.",
            ]);
        }

        return in_array(config('app.env'), ['local', 'testing'], true) ? $code : null;
    }

    private function sendLimiterKey(User $user, string $purpose): string
    {
        return 'wallet-pin-otp:send:'.$user->id.':'.$purpose;
    }

    public function verify(User $user, string $purpose, string $code): void
    {
        $key = 'wallet-pin-otp:verify:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'wallet_pin_otp' => 'Too many attempts. Please try again later.',
            ]);
        }
        RateLimiter::hit($key, 900);

        $result = DB::transaction(function () use ($user, $purpose, $code): string {
            $otp = WalletPinOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($otp?->attempts >= 5) {
                return 'limited';
            }

            if (! $otp || $otp->consumed_at !== null || $otp->verified_at !== null) {
                return 'invalid';
            }

            if ($otp->expires_at->isPast()) {
                return 'expired';
            }

            if (! Hash::check($code, $otp->otp_hash)) {
                $otp->attempts++;
                if ($otp->attempts >= 5) {
                    $otp->consumed_at = now();
                }
                $otp->save();

                return 'invalid';
            }

            $otp->forceFill(['verified_at' => now()])->save();

            return 'valid';
        });

        if ($result !== 'valid') {
            $message = match ($result) {
                'expired' => 'This verification code has expired. Please request a new code.',
                'limited' => 'Too many attempts. Please try again later.',
                default => 'Invalid verification code.',
            };
            throw ValidationException::withMessages([
                'wallet_pin_otp' => $message,
            ]);
        }

        RateLimiter::clear($key);
    }

    public function consumeVerified(User $user, string $purpose): WalletPinOtp
    {
        $this->assertPurposeAllowed($user, $purpose);

        $otp = WalletPinOtp::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNotNull('verified_at')
            ->whereNull('consumed_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if (! $otp || $otp->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'wallet_pin_otp' => 'Verify a current email code before setting your Wallet Transaction PIN.',
            ]);
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return $otp;
    }

    private function assertPurposeAllowed(User $user, string $purpose): void
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw ValidationException::withMessages(['purpose' => 'Choose a valid security action.']);
        }

        $configured = $user->hasWalletTransactionPin();
        if (($purpose === 'wallet_password_set' && $configured)
            || (in_array($purpose, ['wallet_password_change', 'wallet_password_reset'], true) && ! $configured)) {
            throw ValidationException::withMessages([
                'purpose' => 'This Wallet Transaction PIN action is no longer available. Refresh and try again.',
            ]);
        }
    }
}
