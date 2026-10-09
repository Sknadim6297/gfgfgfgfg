<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class InrLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_user_id',
        'source_type',
        'source_id',
        'entry_type',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('INR ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('INR ledger entries are immutable.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function referralCommission(): HasOne
    {
        return $this->hasOne(ReferralCommission::class, 'ledger_entry_id');
    }
}
