<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class WithdrawalRequest extends Model
{
    public const STATUSES = ['pending', 'processing', 'completed', 'rejected'];

    protected $fillable = [
        'request_reference',
        'user_id',
        'submission_key',
        'amount',
        'usdt_inr_rate_snapshot',
        'payout_method',
        'bank_account_holder',
        'bank_name',
        'bank_account_number',
        'bank_ifsc_code',
        'bank_branch_name',
        'bank_account_type',
        'status',
        'transaction_reference',
        'admin_notes',
        'reviewed_by_user_id',
        'requested_at',
        'reviewed_at',
        'completed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'usdt_inr_rate_snapshot' => 'decimal:8',
            'bank_account_holder' => 'encrypted',
            'bank_name' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'bank_ifsc_code' => 'encrypted',
            'bank_branch_name' => 'encrypted',
            'bank_account_type' => 'encrypted',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $withdrawal): void {
            foreach ([
                'request_reference',
                'user_id',
                'submission_key',
                'amount',
                'payout_method',
                'bank_account_holder',
                'bank_name',
                'bank_account_number',
                'bank_ifsc_code',
                'bank_branch_name',
                'bank_account_type',
                'requested_at',
            ] as $attribute) {
                if ($withdrawal->isDirty($attribute)) {
                    throw new LogicException('Withdrawal amount and payout destination snapshots are immutable.');
                }
            }

            if ($withdrawal->isDirty('usdt_inr_rate_snapshot')
                && $withdrawal->getRawOriginal('usdt_inr_rate_snapshot') !== null) {
                throw new LogicException('A withdrawal rate snapshot cannot be changed once recorded.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function inrLedgerEntries(): HasMany
    {
        return $this->hasMany(InrLedgerEntry::class, 'source_id')
            ->where('source_type', 'withdrawal_request');
    }

    public function referralQualification(): HasOne
    {
        return $this->hasOne(ReferralQualification::class, 'qualifying_withdrawal_id');
    }

    public function referralCommissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class, 'triggering_withdrawal_id');
    }
}
