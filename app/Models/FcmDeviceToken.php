<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class FcmDeviceToken extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'token',
        'device_label',
        'last_used_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
        'token_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $deviceToken): void {
            if (! $deviceToken->exists || $deviceToken->isDirty('token')) {
                $deviceToken->token_hash = self::hashToken($deviceToken->token);
            }
        });
    }

    public static function hashToken(?string $token): string
    {
        if (! is_string($token) || trim($token) === '') {
            throw new InvalidArgumentException('An FCM device token must not be empty.');
        }

        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
