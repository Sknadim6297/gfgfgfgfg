<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DemoUsdtLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'deposit_id',
        'actor_user_id',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Demo USDT ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Demo USDT ledger entries are immutable.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
