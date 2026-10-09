<?php

namespace App\Jobs;

use App\Models\FcmNotificationCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class FanoutFcmNotificationCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $campaignId,
        public readonly int $afterDeliveryId = 0,
    ) {}

    public function handle(): void
    {
        $campaign = FcmNotificationCampaign::find($this->campaignId);
        if (! $campaign || $campaign->status !== 'processing') {
            return;
        }

        $deliveryIds = $campaign->deliveries()
            ->where('id', '>', $this->afterDeliveryId)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(500)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach (array_chunk($deliveryIds, 5) as $batch) {
            SendFcmNotificationBatch::dispatch($campaign->id, $batch)->onConnection('database');
        }

        if (count($deliveryIds) === 500) {
            self::dispatch($campaign->id, max($deliveryIds))->onConnection('database');
        }
    }

    public function failed(Throwable $exception): void
    {
        DB::table('fcm_notification_deliveries')
            ->where('campaign_id', $this->campaignId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'error_code' => 'CAMPAIGN_FANOUT_FAILED',
                'failed_at' => now(),
                'updated_at' => now(),
            ]);

        $campaign = FcmNotificationCampaign::find($this->campaignId);
        if ($campaign) {
            $campaign->refreshProgress();
            $campaign->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
            ])->save();
        }
    }
}
