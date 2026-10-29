<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalUsdtTransfer extends Model
{
    public const STATUSES = ['completed'];

    public const CURRENCY = 'USDT';

    protected $fillable = [
        'transfer_reference',
        'submission_key',
        'sender_user_id',
        'recipient_user_id',
        'amount',
        'currency',
        'status',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transfer): void {
            if ($transfer->currency !== self::CURRENCY) {
                throw new LogicException('Internal transfers only support USDT.');
            }
        });

        static::updating(fn () => throw new LogicException('Internal USDT transfers are immutable.'));
        static::deleting(fn () => throw new LogicException('Internal USDT transfers are immutable.'));
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
