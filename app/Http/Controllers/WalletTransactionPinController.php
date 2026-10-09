<?php

namespace App\Http\Controllers;

use App\Exceptions\WalletOtpRateLimitException;
use App\Mail\WalletTransactionPinChanged;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\WalletPinOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class WalletTransactionPinController extends Controller
{
    public function show(Request $request): RedirectResponse
    {
        $this->customer($request);

        return redirect()->route('profile');
    }

    public function sendOtp(Request $request, WalletPinOtpService $otps): JsonResponse
    {
        $user = $this->customer($request);
        $validated = Validator::make($request->all(), [
            'purpose' => ['required', Rule::in(WalletPinOtpService::PURPOSES)],
        ])->validate();

        try {
            $testingOtp = $otps->send($user, $validated['purpose']);
        } catch (WalletOtpRateLimitException $exception) {
            $retryAfter = $exception->retryAfter;

            return response()->json([
                'message' => 'Too many verification code requests. Please try again in '.ceil($retryAfter / 60).' minute'.($retryAfter > 60 ? 's' : '').'.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $response = [
            'message' => 'A verification code was sent to '.$this->maskedEmail($user->email).'.',
        ];
        if (in_array(config('app.env'), ['local', 'testing'], true) && is_string($testingOtp)) {
            $response['testing_otp'] = $testingOtp;
        }

        return response()->json($response);
    }

    public function verifyOtp(Request $request, WalletPinOtpService $otps): JsonResponse
    {
        $user = $this->customer($request);
        $code = (string) $request->input('code', '');
        $request->request->remove('code');
        $validated = Validator::make($request->all(), [
            'purpose' => ['required', Rule::in(WalletPinOtpService::PURPOSES)],
        ])->validate();
        $codeValidator = Validator::make(['code' => $code], [
            'code' => ['required', 'digits:6'],
        ]);
        if ($codeValidator->fails()) {
            return response()->json(['errors' => ['wallet_pin_otp' => ['Invalid verification code.']]], 422);
        }

        $otps->verify($user, $validated['purpose'], $code);

        return response()->json(['message' => 'Email verified. Create your Wallet Transaction PIN.']);
    }

    public function save(Request $request, WalletPinOtpService $otps): JsonResponse
    {
        $user = $this->customer($request);
        $pin = (string) $request->input('wallet_transaction_pin', '');
        $confirmation = (string) $request->input('wallet_transaction_pin_confirmation', '');
        $request->request->remove('wallet_transaction_pin');
        $request->request->remove('wallet_transaction_pin_confirmation');
        $validated = Validator::make($request->all(), [
            'purpose' => ['required', Rule::in(WalletPinOtpService::PURPOSES)],
        ])->validate();
        $pinValidator = Validator::make([
            'wallet_transaction_pin' => $pin,
            'wallet_transaction_pin_confirmation' => $confirmation,
        ], [
            'wallet_transaction_pin' => ['required', 'digits:4'],
            'wallet_transaction_pin_confirmation' => ['required', 'same:wallet_transaction_pin'],
        ]);
        if ($pinValidator->fails()) {
            return response()->json([
                'errors' => ['wallet_pin_otp' => ['Enter and confirm a matching four-digit Wallet Transaction PIN.']],
            ], 422);
        }

        $event = DB::transaction(function () use ($request, $user, $validated, $pin, $otps): string {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $otps->consumeVerified($lockedUser, $validated['purpose']);
            $event = match ($validated['purpose']) {
                'wallet_password_set' => 'WALLET_PASSWORD_SET',
                'wallet_password_change' => 'WALLET_PASSWORD_CHANGED',
                'wallet_password_reset' => 'WALLET_PASSWORD_RESET',
            };
            $lockedUser->forceFill([
                'wallet_transaction_password_hash' => Hash::make($pin),
            ])->save();

            AuditLog::create([
                'actor_user_id' => $lockedUser->id,
                'subject_user_id' => $lockedUser->id,
                'event' => $event,
                'metadata' => [],
                'ip_address' => $request->ip(),
            ]);

            return $event;
        });

        try {
            $action = match ($event) {
                'WALLET_PASSWORD_SET' => 'set',
                'WALLET_PASSWORD_CHANGED' => 'changed',
                default => 'reset',
            };
            Mail::to($user->email)->send(new WalletTransactionPinChanged($action));
        } catch (Throwable $exception) {
            Log::warning('Wallet transaction PIN security notification delivery failed.', [
                'user_id' => $user->id,
                'event' => $event,
                'exception' => $exception::class,
            ]);
        }

        return response()->json(['message' => 'Wallet Transaction PIN '.$this->successVerb($event).' successfully.']);
    }

    private function customer(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        return $user;
    }

    private function maskedEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('*', min(5, max(3, mb_strlen($name) - 1))).'@'.$domain;
    }

    private function successVerb(string $event): string
    {
        return match ($event) {
            'WALLET_PASSWORD_SET' => 'set',
            'WALLET_PASSWORD_CHANGED' => 'changed',
            default => 'reset',
        };
    }
}
