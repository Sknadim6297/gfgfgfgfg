<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BalanceLedgerEntry;
use App\Models\InternalTransferSettings;
use App\Models\InternalUsdtTransfer;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class InternalUsdtTransferService
{
    public function __construct(
        private readonly SellRequestService $sells,
        private readonly WalletTransactionPinService $transactionPins,
    ) {}

    public function settings(): ?InternalTransferSettings
    {
        return InternalTransferSettings::query()->find(1);
    }

    public function enabled(): bool
    {
        return (bool) $this->settings()?->is_enabled;
    }

    public function locateRecipient(User $sender, string $customerId): User
    {
        $customerId = $this->normalizeCustomerId($customerId);
        if ($customerId === strtoupper((string) $sender->customer_id)) {
            throw ValidationException::withMessages([
                'recipient_customer_id' => 'You cannot transfer USDT to your own account.',
            ]);
        }

        $recipient = User::query()
            ->where('customer_id', $customerId)
            ->where('role', 'user')
            ->first();

        if (! $recipient) {
            throw ValidationException::withMessages([
                'recipient_customer_id' => 'No member was found with that Customer ID.',
            ]);
        }

        if ($recipient->account_status !== 'active') {
            throw ValidationException::withMessages([
                'recipient_customer_id' => 'This member cannot receive internal transfers.',
            ]);
        }

        return $recipient;
    }

    public function transfer(
        User $sender,
        string $customerId,
        string $amount,
        string $transactionPin,
        string $submissionKey,
        ?string $ipAddress,
    ): InternalUsdtTransfer {
        $amount = $this->normalizeAmount($amount);
        $this->assertWithinLimits($amount);
        $recipient = $this->locateRecipient($sender, $customerId);

        if (! $sender->hasWalletTransactionPin()) {
            throw ValidationException::withMessages([
                'wallet_transaction_pin' => 'Set a Wallet Transaction PIN before transferring USDT.',
            ]);
        }

        $this->transactionPins->assertValid($sender, $transactionPin, $ipAddress);

        try {
            return DB::transaction(function () use ($sender, $recipient, $amount, $submissionKey, $ipAddress): InternalUsdtTransfer {
                $lockedUsers = User::query()
                    ->whereIn('id', [$sender->id, $recipient->id])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $lockedSender = $lockedUsers->get($sender->id);
                $lockedRecipient = $lockedUsers->get($recipient->id);

                if (! $lockedSender || ! $lockedRecipient) {
                    throw ValidationException::withMessages([
                        'recipient_customer_id' => 'No member was found with that Customer ID.',
                    ]);
                }

                if ($lockedSender->account_status !== 'active') {
                    throw ValidationException::withMessages([
                        'amount' => 'Your account cannot send internal transfers.',
                    ]);
                }

                if ($lockedSender->id === $lockedRecipient->id) {
                    throw ValidationException::withMessages([
                        'recipient_customer_id' => 'You cannot transfer USDT to your own account.',
                    ]);
                }

                if ($lockedRecipient->role !== 'user' || $lockedRecipient->account_status !== 'active') {
                    throw ValidationException::withMessages([
                        'recipient_customer_id' => 'This member cannot receive internal transfers.',
                    ]);
                }

                $existing = InternalUsdtTransfer::query()
                    ->where('sender_user_id', $lockedSender->id)
                    ->where('submission_key', $submissionKey)
                    ->first();
                if ($existing) {
                    $this->assertReplayMatches($existing, $lockedRecipient->id, $amount);

                    return $existing;
                }

                $this->assertWithinLimits($amount);
                $available = $this->availableBalance($lockedSender);
                if (Decimal::compare($amount, $available, 8) > 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'The transfer amount exceeds your available USDT balance.',
                    ]);
                }

                $transfer = InternalUsdtTransfer::create([
                    'transfer_reference' => $this->reference(),
                    'submission_key' => $submissionKey,
                    'sender_user_id' => $lockedSender->id,
                    'recipient_user_id' => $lockedRecipient->id,
                    'amount' => $amount,
                    'currency' => InternalUsdtTransfer::CURRENCY,
                    'status' => 'completed',
                    'ip_address' => $ipAddress,
                ]);

                $lockedSender->forceFill([
                    'balance' => Decimal::subtract((string) $lockedSender->balance, $amount, 8),
                ])->save();

                BalanceLedgerEntry::create([
                    'user_id' => $lockedSender->id,
                    'source_type' => 'internal_usdt_transfer',
                    'source_id' => $transfer->id,
                    'actor_user_id' => $lockedSender->id,
                    'entry_type' => 'debit',
                    'amount' => $amount,
                ]);

                $lockedRecipient->forceFill([
                    'balance' => Decimal::add((string) $lockedRecipient->balance, $amount, 8),
                ])->save();

                BalanceLedgerEntry::create([
                    'user_id' => $lockedRecipient->id,
                    'source_type' => 'internal_usdt_transfer',
                    'source_id' => $transfer->id,
                    'actor_user_id' => $lockedSender->id,
                    'entry_type' => 'credit',
                    'amount' => $amount,
                ]);

                AuditLog::create([
                    'actor_user_id' => $lockedSender->id,
                    'subject_user_id' => $lockedRecipient->id,
                    'event' => 'internal_usdt_transfer.completed',
                    'auditable_type' => InternalUsdtTransfer::class,
                    'auditable_id' => $transfer->id,
                    'metadata' => [
                        'reference' => $transfer->transfer_reference,
                        'amount' => $amount,
                        'currency' => InternalUsdtTransfer::CURRENCY,
                        'sender_customer_id' => $lockedSender->customer_id,
                        'recipient_customer_id' => $lockedRecipient->customer_id,
                    ],
                    'ip_address' => $ipAddress,
                ]);

                return $transfer;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = InternalUsdtTransfer::query()
                ->where('sender_user_id', $sender->id)
                ->where('submission_key', $submissionKey)
                ->first();

            if ($existing) {
                $this->assertReplayMatches($existing, $recipient->id, $amount);

                return $existing;
            }

            throw ValidationException::withMessages([
                'amount' => 'This transfer could not be completed. Please try again.',
            ]);
        }
    }

    public function availableBalance(User $user): string
    {
        try {
            return $this->sells->availableBalance($user);
        } catch (LogicException) {
            throw ValidationException::withMessages([
                'amount' => 'Your available USDT balance is inconsistent. Contact support before transferring.',
            ]);
        }
    }

    private function assertWithinLimits(string $amount): void
    {
        $settings = $this->settings();
        if (! $settings?->is_enabled) {
            throw ValidationException::withMessages([
                'amount' => 'Internal USDT transfers are currently unavailable.',
            ]);
        }

        if (Decimal::compare($amount, '0', 8) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter a transfer amount greater than zero.',
            ]);
        }

        $minimum = (string) $settings->minimum_amount;
        if (Decimal::compare($amount, $minimum, 8) < 0) {
            throw ValidationException::withMessages([
                'amount' => 'The minimum internal transfer is '.$minimum.' USDT.',
            ]);
        }

        if ($settings->maximum_amount !== null && Decimal::compare($amount, (string) $settings->maximum_amount, 8) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'The maximum internal transfer is '.$settings->maximum_amount.' USDT.',
            ]);
        }
    }

    private function assertReplayMatches(InternalUsdtTransfer $existing, int $recipientId, string $amount): void
    {
        if (
            (int) $existing->recipient_user_id !== $recipientId
            || $existing->currency !== InternalUsdtTransfer::CURRENCY
            || Decimal::compare((string) $existing->amount, $amount, 8) !== 0
        ) {
            throw ValidationException::withMessages([
                'submission_key' => 'This transfer confirmation was already used for a different transfer.',
            ]);
        }
    }

    private function normalizeCustomerId(string $customerId): string
    {
        return strtoupper(trim($customerId));
    }

    private function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);
        if (preg_match('/\A(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?\z/', $amount) !== 1) {
            throw ValidationException::withMessages([
                'amount' => 'Enter a valid USDT amount.',
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return $whole.'.'.str_pad($fraction, 8, '0');
    }

    private function reference(): string
    {
        do {
            $reference = 'QPMTRF'.Str::upper(Str::random(10));
        } while (InternalUsdtTransfer::query()->where('transfer_reference', $reference)->exists());

        return $reference;
    }
}
