<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ReferralQualification extends Model
{
    protected $fillable = [
        'qualified_user_id',
        'qualifying_withdrawal_id',
        'cumulative_usdt_equivalent',
        'threshold_usdt',
        'qualified_at',
    ];

    protected function casts(): array
    {
        return [
            'cumulative_usdt_equivalent' => 'decimal:8',
            'threshold_usdt' => 'decimal:8',
            'qualified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Referral qualification records are immutable.'));
        static::deleting(fn () => throw new LogicException('Referral qualification records are immutable.'));
    }

    public function qualifiedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_user_id');
    }

    public function qualifyingWithdrawal(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class, 'qualifying_withdrawal_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class);
    }
}
