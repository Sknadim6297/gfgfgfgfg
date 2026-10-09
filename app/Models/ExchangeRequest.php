<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function getRouteKeyName(): string
    {
        return 'request_reference';
    }
}
