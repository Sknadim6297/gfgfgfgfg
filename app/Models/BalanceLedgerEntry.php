<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'deposit_id',
        'source_type',
        'source_id',
        'actor_user_id',
        'entry_type',
        'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Balance ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Balance ledger entries are immutable.'));
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
