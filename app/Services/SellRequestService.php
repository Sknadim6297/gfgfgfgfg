<?php

namespace App\Services;

use App\Exceptions\StaleSellQuoteException;
use App\Models\AuditLog;
use App\Models\BalanceLedgerEntry;
use App\Models\ExchangeRequest;
use App\Models\InrLedgerEntry;
use App\Models\User;
use App\Notifications\ExchangeStatusUpdated;
use App\Support\Decimal;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class SellRequestService
{
    public function __construct(
        private readonly ExchangeRateResolver $rates,
        private readonly WalletTransactionPinService $transactionPins,
    ) {}

    public function availableBalance(User $user): string
    {
        $heldAmount = '0.00000000';
        foreach ($user->exchangeRequests()
            ->whereNotNull('available_usd_before')
            ->where('status', 'pending')
            ->get(['usdt_amount']) as $request) {
            $heldAmount = Decimal::add($heldAmount, (string) $request->usdt_amount, 8);
        }

        if (Decimal::compare((string) $user->balance, $heldAmount, 8) < 0) {
            throw new \LogicException('Pending sell reservations exceed the recorded USD balance.');
        }

        return Decimal::subtract((string) $user->balance, $heldAmount, 8);
    }

    public function quote(User $user, string $amount): array
    {
        $plan = $this->rates->forAmount($amount);
        $available = $this->availableBalance($user);

        if (Decimal::compare($amount, $available, 8) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'The sell amount exceeds your available recorded USD balance.',
            ]);
        }

        $quote = [
            'user_id' => $user->id,
            'amount' => $amount,
            'rate_id' => $plan->id,
            'rate' => (string) $plan->rate,
            'rate_plan_key' => (string) $plan->plan_key,
            'rate_plan_name' => (string) $plan->name,
            'inr_amount' => $this->rates->inrAmount($amount, (string) $plan->rate),
        ];

        return [
            ...$quote,
            'available_balance' => $available,
            'quote_token' => Crypt::encryptString(json_encode($quote, JSON_THROW_ON_ERROR)),
        ];
    }

    public function submit(
        User $customer,
        string $amount,
        string $submissionKey,
        string $quoteToken,
        string $transactionPin,
        ?string $ipAddress,
    ): ExchangeRequest {
        $quote = $this->decodeQuote($quoteToken);
        if (
            ($quote['user_id'] ?? null) !== $customer->id
            || ($quote['amount'] ?? null) !== $amount
        ) {
            throw ValidationException::withMessages([
                'amount' => 'The sell confirmation is invalid. Please review your request again.',
            ]);
        }

        return DB::transaction(function () use ($customer, $amount, $submissionKey, $quote, $transactionPin, $ipAddress): ExchangeRequest {
            $user = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $this->transactionPins->assertValid($user, $transactionPin, $ipAddress);
            $existing = $user->exchangeRequests()->where('submission_key', $submissionKey)->first();
            if ($existing) {
                return $existing;
            }

            $plan = $this->rates->forAmount($amount);
            if (
                (int) ($quote['rate_id'] ?? 0) !== $plan->id
                || Decimal::compare((string) ($quote['rate'] ?? ''), (string) $plan->rate, 8) !== 0
                || ($quote['rate_plan_key'] ?? null) !== $plan->plan_key
                || ($quote['rate_plan_name'] ?? null) !== $plan->name
            ) {
                throw new StaleSellQuoteException('The quote changed while this confirmation was open.');
            }

            $available = $this->availableBalance($user);
            if (Decimal::compare($amount, $available, 8) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'The sell amount exceeds your available recorded USD balance.',
                ]);
            }

            $exchange = ExchangeRequest::create([
                'user_id' => $user->id,
                'request_reference' => $this->requestReference(),
                'submission_key' => $submissionKey,
                'rate_plan_key' => $plan->plan_key,
                'rate_plan_name' => $plan->name,
                'usdt_amount' => $amount,
                'exchange_rate' => (string) $plan->rate,
                'inr_amount' => $this->rates->inrAmount($amount, (string) $plan->rate),
                'available_usd_before' => $available,
                'status' => 'pending',
            ]);

            BalanceLedgerEntry::create([
                'user_id' => $user->id,
                'source_type' => 'exchange_request',
                'source_id' => $exchange->id,
                'actor_user_id' => $user->id,
                'entry_type' => 'hold',
                'amount' => $amount,
            ]);

            AuditLog::create([
                'actor_user_id' => $user->id,
                'subject_user_id' => $user->id,
                'event' => 'SELL_REQUEST_SUBMITTED',
                'auditable_type' => ExchangeRequest::class,
                'auditable_id' => $exchange->id,
                'metadata' => [
                    'reference' => $exchange->request_reference,
                    'customer_id' => $user->customer_id,
                    'usdt_amount' => $exchange->usdt_amount,
                    'rate' => $exchange->exchange_rate,
                    'rate_plan' => $exchange->rate_plan_key,
                    'inr_amount' => $exchange->inr_amount,
                ],
                'ip_address' => $ipAddress,
            ]);

            DB::afterCommit(fn () => $user->notify(new ExchangeStatusUpdated($exchange)));

            return $exchange;
        });
    }

    public function approve(ExchangeRequest $request, User $admin, ?string $ipAddress): ExchangeRequest
    {
        return DB::transaction(function () use ($request, $admin, $ipAddress): ExchangeRequest {
            $exchange = ExchangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($exchange->available_usd_before !== null, 422, 'This legacy exchange request cannot use sell-request approval.');
            abort_unless($exchange->status === 'pending', 409, 'Only pending sell requests can be approved.');

            $user = User::query()->whereKey($exchange->user_id)->lockForUpdate()->firstOrFail();
            abort_unless(
                BalanceLedgerEntry::query()
                    ->where('source_type', 'exchange_request')
                    ->where('source_id', $exchange->id)
                    ->where('user_id', $user->id)
                    ->where('entry_type', 'hold')
                    ->where('amount', $exchange->usdt_amount)
                    ->exists(),
                409,
                'The USD reservation for this sell request is missing or inconsistent.'
            );
            abort_unless(
                ! BalanceLedgerEntry::query()
                    ->where('source_type', 'exchange_request')
                    ->where('source_id', $exchange->id)
                    ->whereIn('entry_type', ['capture', 'release', 'debit'])
                    ->exists(),
                409,
                'The USD reservation was already finalized.'
            );
            abort_unless(
                ! BalanceLedgerEntry::query()
                    ->where('source_type', 'exchange_request')
                    ->where('source_id', $exchange->id)
                    ->where('entry_type', 'debit')
                    ->exists(),
                409,
                'This sell request has already debited the customer USD balance.'
            );
            abort_if(
                Decimal::compare((string) $user->balance, (string) $exchange->usdt_amount, 8) < 0,
                409,
                'The recorded USD balance is lower than the reserved sell amount.'
            );

            BalanceLedgerEntry::create([
                'user_id' => $user->id,
                'source_type' => 'exchange_request',
                'source_id' => $exchange->id,
                'actor_user_id' => $admin->id,
                'entry_type' => 'capture',
                'amount' => $exchange->usdt_amount,
            ]);
            BalanceLedgerEntry::create([
                'user_id' => $user->id,
                'source_type' => 'exchange_request',
                'source_id' => $exchange->id,
                'actor_user_id' => $admin->id,
                'entry_type' => 'debit',
                'amount' => $exchange->usdt_amount,
            ]);
            $user->forceFill([
                'balance' => Decimal::subtract((string) $user->balance, (string) $exchange->usdt_amount, 8),
            ])->save();

            abort_unless(
                ! InrLedgerEntry::query()
                    ->where('source_type', 'exchange_request')
                    ->where('source_id', $exchange->id)
                    ->where('entry_type', 'credit')
                    ->exists(),
                409,
                'This sell request has already credited the customer INR balance.'
            );
            InrLedgerEntry::create([
                'user_id' => $user->id,
                'actor_user_id' => $admin->id,
                'source_type' => 'exchange_request',
                'source_id' => $exchange->id,
                'entry_type' => 'credit',
                'amount' => $exchange->inr_amount,
            ]);
            $user->forceFill([
                'inr_balance' => Decimal::add((string) $user->inr_balance, (string) $exchange->inr_amount, 2),
            ])->save();

            $exchange->forceFill([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by_user_id' => $admin->id,
            ])->save();

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'subject_user_id' => $user->id,
                'event' => 'SELL_REQUEST_APPROVED',
                'auditable_type' => ExchangeRequest::class,
                'auditable_id' => $exchange->id,
                'metadata' => [
                    'reference' => $exchange->request_reference,
                    'customer_id' => $user->customer_id,
                    'usdt_amount' => $exchange->usdt_amount,
                    'rate' => $exchange->exchange_rate,
                    'rate_plan' => $exchange->rate_plan_key,
                    'inr_amount' => $exchange->inr_amount,
                ],
                'ip_address' => $ipAddress,
            ]);

            DB::afterCommit(fn () => $user->notify(new ExchangeStatusUpdated($exchange)));

            return $exchange;
        });
    }

    public function reject(ExchangeRequest $request, User $admin, string $reason, ?string $ipAddress): ExchangeRequest
    {
        return DB::transaction(function () use ($request, $admin, $reason, $ipAddress): ExchangeRequest {
            $exchange = ExchangeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($exchange->available_usd_before !== null, 422, 'This legacy exchange request cannot use sell-request rejection.');
            abort_unless($exchange->status === 'pending', 409, 'Only pending sell requests can be rejected.');

            $user = User::query()->whereKey($exchange->user_id)->lockForUpdate()->firstOrFail();
            abort_unless(
                BalanceLedgerEntry::query()
                    ->where('source_type', 'exchange_request')
                    ->where('source_id', $exchange->id)
                    ->where('user_id', $user->id)
                    ->where('entry_type', 'hold')
                    ->where('amount', $exchange->usdt_amount)
                    ->exists(),
                409,
                'The USD reservation for this sell request is missing or inconsistent.'
            );
            abort_unless(
                ! BalanceLedgerEntry::query()
                    ->where('source_type', 'exchange_request')
                    ->where('source_id', $exchange->id)
                    ->whereIn('entry_type', ['capture', 'release', 'debit'])
                    ->exists(),
                409,
                'The USD reservation was already finalized.'
            );

            BalanceLedgerEntry::create([
                'user_id' => $user->id,
                'source_type' => 'exchange_request',
                'source_id' => $exchange->id,
                'actor_user_id' => $admin->id,
                'entry_type' => 'release',
                'amount' => $exchange->usdt_amount,
            ]);
            $exchange->forceFill([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejected_by_user_id' => $admin->id,
                'rejection_reason' => trim($reason),
            ])->save();

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'subject_user_id' => $user->id,
                'event' => 'SELL_REQUEST_REJECTED',
                'auditable_type' => ExchangeRequest::class,
                'auditable_id' => $exchange->id,
                'metadata' => [
                    'reference' => $exchange->request_reference,
                    'customer_id' => $user->customer_id,
                    'usdt_amount' => $exchange->usdt_amount,
                    'rate' => $exchange->exchange_rate,
                    'rate_plan' => $exchange->rate_plan_key,
                    'inr_amount' => $exchange->inr_amount,
                ],
                'ip_address' => $ipAddress,
            ]);

            DB::afterCommit(fn () => $user->notify(new ExchangeStatusUpdated($exchange)));

            return $exchange;
        });
    }

    public function finalizeLegacyExchange(ExchangeRequest $exchange, User $admin): void
    {
        $user = User::query()->whereKey($exchange->user_id)->lockForUpdate()->firstOrFail();
        abort_if(
            Decimal::compare((string) $user->balance, (string) $exchange->usdt_amount, 8) < 0,
            422,
            'The customer no longer has enough USD to complete this exchange.'
        );
        abort_if(
            BalanceLedgerEntry::query()
                ->where('source_type', 'exchange_request')
                ->where('source_id', $exchange->id)
                ->where('entry_type', 'debit')
                ->exists(),
            409,
            'This exchange has already debited the customer USD balance.'
        );
        abort_if(
            InrLedgerEntry::query()
                ->where('source_type', 'exchange_request')
                ->where('source_id', $exchange->id)
                ->where('entry_type', 'credit')
                ->exists(),
            409,
            'This exchange has already credited the customer INR balance.'
        );

        BalanceLedgerEntry::create([
            'user_id' => $user->id,
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
            'actor_user_id' => $admin->id,
            'entry_type' => 'debit',
            'amount' => $exchange->usdt_amount,
        ]);
        InrLedgerEntry::create([
            'user_id' => $user->id,
            'actor_user_id' => $admin->id,
            'source_type' => 'exchange_request',
            'source_id' => $exchange->id,
            'entry_type' => 'credit',
            'amount' => $exchange->inr_amount,
        ]);
        $user->forceFill([
            'balance' => Decimal::subtract((string) $user->balance, (string) $exchange->usdt_amount, 8),
            'inr_balance' => Decimal::add((string) $user->inr_balance, (string) $exchange->inr_amount, 2),
        ])->save();
    }

    private function decodeQuote(string $token): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages([
                'amount' => 'The sell confirmation is invalid. Please review your request again.',
            ]);
        }

        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'amount' => 'The sell confirmation is invalid. Please review your request again.',
            ]);
        }

        return $data;
    }

    private function requestReference(): string
    {
        do {
            $reference = 'QPMSELL'.strtoupper(Str::random(10));
        } while (ExchangeRequest::query()->where('request_reference', $reference)->exists());

        return $reference;
    }
}
