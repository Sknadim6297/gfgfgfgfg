<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ReferralCommissionLevel extends Model
{
    protected $fillable = [
        'level',
        'amount',
        'is_active',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $level): void {
            if ($level->isDirty('level')) {
                throw new LogicException('A referral commission level cannot be changed.');
            }
        });

        static::deleting(fn () => throw new LogicException('Referral commission levels must be deactivated, not deleted.'));
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class);
    }
}
