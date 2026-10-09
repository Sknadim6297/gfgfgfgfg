<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositSettings extends Model
{
    protected $fillable = [
        'id',
        'qr_path',
        'recipient_name',
        'instructions',
        'updated_by_user_id',
    ];

    public static function isManagedQrPath(?string $path): bool
    {
        return is_string($path) && preg_match('~\Adeposits/qr/[A-Za-z0-9._-]+\z~D', $path) === 1;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
