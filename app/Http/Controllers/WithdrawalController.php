<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InrLedgerEntry;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WalletTransactionPinService;
use App\Support\Decimal;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WithdrawalController extends Controller
{
    public function create(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        return view('pages.withdrawal.create', [
            'user' => $user,
            'hasWalletPin' => $user->hasWalletTransactionPin(),
            'bankVerificationStatus' => $user->bankVerificationStatus(),
            'submissionKey' => (string) Str::uuid(),
            'maskedEmail' => $this->maskedEmail($user->email),
        ]);
    }

    public function store(Request $request, WalletTransactionPinService $transactionPins): RedirectResponse
    {
        $transactionPin = (string) $request->input('wallet_transaction_pin', '');
        $request->request->remove('wallet_transaction_pin');
        $validated = $request->validate([
            'submission_key' => ['required', 'uuid'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/'],
            'payout_method' => ['required', 'in:cash,bank'],
        ]);
        $pinValidator = Validator::make(['wallet_transaction_pin' => $transactionPin], [
            'wallet_transaction_pin' => ['required', 'digits:4'],
        ]);
        if ($pinValidator->fails()) {
            throw ValidationException::withMessages([
                'wallet_transaction_pin' => 'Enter your four-digit Wallet Transaction PIN.',
            ]);
        }
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        if (Decimal::compare($validated['amount'], '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter a withdrawal amount greater than zero.']);
        }
        $amount = Money::normalizeUsd($validated['amount']);

        try {
            $withdrawal = DB::transaction(function () use ($request, $user, $validated, $amount, $transactionPin, $transactionPins): WithdrawalRequest {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $transactionPins->assertValid($lockedUser, $transactionPin, $request->ip());
                $existing = WithdrawalRequest::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('submission_key', $validated['submission_key'])
                    ->first();
                if ($existing) {
                    return $existing;
                }

                $bankStatus = $lockedUser->bankVerificationStatus();
                if ($validated['payout_method'] === 'bank' && $bankStatus !== 'verified') {
                    $message = match ($bankStatus) {
                        'pending' => 'Your bank details are pending verification.',
                        'rejected' => 'Your bank details were not verified. Please update them and submit again.',
                        default => 'Add your bank details before withdrawing.',
                    };

                    throw ValidationException::withMessages(['bank' => $message]);
                }

                if (Decimal::compare((string) $lockedUser->inr_balance, $amount, 2) < 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'The withdrawal amount exceeds your available INR balance.',
                    ]);
                }

                $withdrawal = WithdrawalRequest::create([
                    'request_reference' => $this->requestReference(),
                    'user_id' => $lockedUser->id,
                    'submission_key' => $validated['submission_key'],
                    'amount' => $amount,
                    'payout_method' => $validated['payout_method'],
                    'bank_account_holder' => $validated['payout_method'] === 'bank' ? $lockedUser->account_holder_name : null,
                    'bank_name' => $validated['payout_method'] === 'bank' ? $lockedUser->bank_name : null,
                    'bank_account_number' => $validated['payout_method'] === 'bank' ? $lockedUser->account_number : null,
                    'bank_ifsc_code' => $validated['payout_method'] === 'bank' ? $lockedUser->ifsc_code : null,
                    'bank_branch_name' => $validated['payout_method'] === 'bank' ? $lockedUser->branch_name : null,
                    'bank_account_type' => $validated['payout_method'] === 'bank' ? $lockedUser->account_type : null,
                    'status' => 'pending',
                    'requested_at' => now(),
                ]);

                InrLedgerEntry::create([
                    'user_id' => $lockedUser->id,
                    'actor_user_id' => $lockedUser->id,
                    'source_type' => 'withdrawal_request',
                    'source_id' => $withdrawal->id,
                    'entry_type' => 'hold',
                    'amount' => $amount,
                ]);
                DB::table('users')->where('id', $lockedUser->id)->decrement('inr_balance', $amount);

                AuditLog::create([
                    'actor_user_id' => $lockedUser->id,
                    'subject_user_id' => $lockedUser->id,
                    'event' => 'withdrawal.submitted',
                    'auditable_type' => WithdrawalRequest::class,
                    'auditable_id' => $withdrawal->id,
                    'metadata' => [
                        'request_reference' => $withdrawal->request_reference,
                        'amount' => $amount,
                        'payout_method' => $withdrawal->payout_method,
                    ],
                    'ip_address' => $request->ip(),
                ]);

                return $withdrawal;
            });
        } catch (QueryException $exception) {
            $withdrawal = WithdrawalRequest::query()
                ->where('user_id', $user->id)
                ->where('submission_key', $validated['submission_key'])
                ->first();
            if (! $withdrawal) {
                throw $exception;
            }
        }

        $message = match ($withdrawal->status) {
            'completed' => 'This withdrawal was completed.',
            'rejected' => 'This withdrawal was rejected and its reserved INR was released.',
            'processing' => 'This withdrawal is being processed.',
            default => 'Withdrawal request submitted successfully. It is pending admin review.',
        };

        return redirect()->route('wallet.withdrawals.show', $withdrawal)->with('status', $message);
    }

    public function show(Request $request, WithdrawalRequest $withdrawalRequest): View
    {
        abort_unless($withdrawalRequest->user_id === $request->user('web')?->id, 404);

        return view('pages.withdrawal.show', ['withdrawal' => $withdrawalRequest]);
    }

    public function invoice(Request $request, WithdrawalRequest $withdrawalRequest): Response
    {
        abort_unless($withdrawalRequest->user_id === $request->user('web')?->id, 404);
        abort_unless($withdrawalRequest->status === 'completed', 404);

        return response()
            ->view('pages.withdrawal.invoice', [
                'withdrawal' => $withdrawalRequest,
                'user' => $request->user('web'),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    private function maskedEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('*', min(5, max(3, mb_strlen($name) - 1))).'@'.$domain;
    }

    private function requestReference(): string
    {
        do {
            $reference = 'WDR-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
        } while (WithdrawalRequest::query()->where('request_reference', $reference)->exists());

        return $reference;
    }

}
