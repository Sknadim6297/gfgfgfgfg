<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ReferralCommission extends Model
{
    public const STATUSES = ['pending', 'credited', 'reversed'];

    protected $fillable = [
        'referral_qualification_id',
        'referral_commission_level_id',
        'beneficiary_user_id',
        'qualified_user_id',
        'level',
        'amount',
        'configuration_snapshot',
        'triggering_withdrawal_id',
        'triggering_exchange_request_id',
        'ledger_entry_id',
        'status',
        'qualified_at',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'amount' => 'decimal:2',
            'configuration_snapshot' => 'array',
            'qualified_at' => 'datetime',
            'credited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $commission): void {
            foreach ([
                'referral_qualification_id',
                'referral_commission_level_id',
                'beneficiary_user_id',
                'qualified_user_id',
                'level',
                'amount',
                'configuration_snapshot',
                'triggering_withdrawal_id',
                'triggering_exchange_request_id',
                'qualified_at',
            ] as $attribute) {
                if ($commission->isDirty($attribute)) {
                    throw new LogicException('Referral commission financial details are immutable.');
                }
            }

            if ($commission->isDirty('ledger_entry_id')
                && $commission->getRawOriginal('ledger_entry_id') !== null) {
                throw new LogicException('A referral commission ledger link cannot be changed once recorded.');
            }
        });

        static::deleting(fn () => throw new LogicException('Referral commission records are immutable.'));
    }

    public function qualification(): BelongsTo
    {
        return $this->belongsTo(ReferralQualification::class, 'referral_qualification_id');
    }

    public function commissionLevel(): BelongsTo
    {
        return $this->belongsTo(ReferralCommissionLevel::class, 'referral_commission_level_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function qualifiedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_user_id');
    }

    public function triggeringWithdrawal(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class, 'triggering_withdrawal_id');
    }

    public function triggeringExchangeRequest(): BelongsTo
    {
        return $this->belongsTo(ExchangeRequest::class, 'triggering_exchange_request_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(InrLedgerEntry::class, 'ledger_entry_id');
    }
}
