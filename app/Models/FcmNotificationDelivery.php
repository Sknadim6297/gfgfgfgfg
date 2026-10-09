<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FcmNotificationDelivery extends Model
{
    protected $fillable = [
        'campaign_id',
        'fcm_device_token_id',
        'token_hash',
        'status',
        'attempts',
        'error_code',
        'fcm_message_name',
        'processing_at',
        'accepted_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'processing_at' => 'datetime',
            'accepted_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(FcmNotificationCampaign::class, 'campaign_id');
    }

    public function deviceToken(): BelongsTo
    {
        return $this->belongsTo(FcmDeviceToken::class, 'fcm_device_token_id');
    }
}
