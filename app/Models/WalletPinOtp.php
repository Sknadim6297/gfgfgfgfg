<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletPinOtp extends Model
{
    protected $fillable = [
        'user_id',
        'purpose',
        'otp_hash',
        'expires_at',
        'attempts',
        'verified_at',
        'consumed_at',
    ];

    protected $hidden = ['otp_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
