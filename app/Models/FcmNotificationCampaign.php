<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class FcmNotificationCampaign extends Model
{
    protected $fillable = [
        'admin_id',
        'target_user_id',
        'audience',
        'title',
        'message',
        'destination_url',
        'status',
        'recipient_count',
        'accepted_count',
        'failed_count',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(FcmNotificationDelivery::class, 'campaign_id');
    }

    public function pendingCount(): int
    {
        return $this->deliveries()->whereIn('status', ['pending', 'processing'])->count();
    }

    public function refreshProgress(): void
    {
        DB::transaction(function (): void {
            $campaign = self::query()->whereKey($this->getKey())->lockForUpdate()->first();
            if (! $campaign) {
                return;
            }

            $counts = $campaign->deliveries()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $accepted = (int) ($counts['accepted'] ?? 0);
            $failed = (int) ($counts['failed'] ?? 0);
            $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0);

            $campaign->accepted_count = $accepted;
            $campaign->failed_count = $failed;

            if ($campaign->status !== 'failed' && $pending === 0 && $campaign->status !== 'queued') {
                $campaign->status = $failed > 0 ? 'completed_with_failures' : 'completed';
                $campaign->completed_at ??= now();
            } elseif ($campaign->status === 'queued' && $pending > 0) {
                $campaign->status = 'processing';
                $campaign->started_at ??= now();
            }

            $campaign->save();
            $this->setRawAttributes($campaign->getAttributes(), true);
        });
    }
}
