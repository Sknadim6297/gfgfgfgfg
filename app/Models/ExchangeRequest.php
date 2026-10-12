<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use LogicException;

class ExchangeRequest extends Model
{
    public const STATUSES = ['pending', 'approved', 'processing', 'completed', 'rejected'];

    protected $fillable = [
        'user_id',
        'request_reference',
        'submission_key',
        'rate_plan_key',
        'rate_plan_name',
        'usdt_amount',
        'exchange_rate',
        'inr_amount',
        'available_usd_before',
        'status',
        'completed_at',
        'approved_at',
        'approved_by_user_id',
        'rejected_at',
        'rejected_by_user_id',
        'rejection_reason',
        'transaction_reference',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'usdt_amount' => 'decimal:8',
            'exchange_rate' => 'decimal:8',
            'inr_amount' => 'decimal:2',
            'available_usd_before' => 'decimal:8',
            'completed_at' => 'datetime',
            'referral_eligible_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $exchange): void {
            if (filled($exchange->request_reference)) {
                return;
            }

            do {
                $reference = 'QPMSELL'.Str::upper(Str::random(10));
            } while (self::query()->where('request_reference', $reference)->exists());

            $exchange->request_reference = $reference;
        });

        static::updating(function (self $exchange): void {
            foreach ([
                'user_id',
                'request_reference',
                'submission_key',
                'rate_plan_key',
                'rate_plan_name',
                'usdt_amount',
                'exchange_rate',
                'inr_amount',
                'available_usd_before',
            ] as $attribute) {
                if ($exchange->isDirty($attribute)) {
                    throw new LogicException('Exchange amount and rate snapshots are immutable.');
                }
            }

            if ($exchange->isDirty('referral_eligible_at')) {
                if ($exchange->getRawOriginal('referral_eligible_at') !== null
                    || $exchange->getRawOriginal('status') !== 'pending'
                    || $exchange->status !== 'completed'
                    || $exchange->completed_at === null
                    || $exchange->available_usd_before === null) {
                    throw new LogicException('Referral sell eligibility can only be recorded once during a completed sell conversion.');
                }
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function referralQualification(): HasOne
    {
        return $this->hasOne(ReferralQualification::class, 'qualifying_exchange_request_id');
    }

    public function triggeredReferralCommissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class, 'triggering_exchange_request_id');
    }

    public function scopeCompletedSell(Builder $query): Builder
    {
        return $query
            ->whereNotNull('available_usd_before')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereExists(function ($ledger): void {
                $ledger->selectRaw('1')
                    ->from('balance_ledger_entries')
                    ->whereColumn('balance_ledger_entries.source_id', 'exchange_requests.id')
                    ->whereColumn('balance_ledger_entries.user_id', 'exchange_requests.user_id')
                    ->whereColumn('balance_ledger_entries.amount', 'exchange_requests.usdt_amount')
                    ->where('balance_ledger_entries.source_type', 'exchange_request')
                    ->where('balance_ledger_entries.entry_type', 'debit');
            })
            ->whereExists(function ($ledger): void {
                $ledger->selectRaw('1')
                    ->from('inr_ledger_entries')
                    ->whereColumn('inr_ledger_entries.source_id', 'exchange_requests.id')
                    ->whereColumn('inr_ledger_entries.user_id', 'exchange_requests.user_id')
                    ->whereColumn('inr_ledger_entries.amount', 'exchange_requests.inr_amount')
                    ->where('inr_ledger_entries.source_type', 'exchange_request')
                    ->where('inr_ledger_entries.entry_type', 'credit');
            });
    }

    public function getRouteKeyName(): string
    {
        return 'request_reference';
    }
}
