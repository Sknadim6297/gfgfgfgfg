<?php

namespace App\Jobs;

use App\Models\FcmDeviceToken;
use App\Models\FcmNotificationCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class BuildFcmNotificationCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $campaignId) {}

    public function handle(): void
    {
        $campaign = FcmNotificationCampaign::find($this->campaignId);
        if (! $campaign || $campaign->status !== 'queued') {
            return;
        }

        FcmDeviceToken::query()
            ->whereHas('user', function ($query) use ($campaign): void {
                $query->where('role', 'user')->where('account_status', 'active');
                if ($campaign->audience === 'specific_user') {
                    $query->whereKey($campaign->target_user_id);
                }
            })
            ->chunkById(250, function ($deviceTokens) use ($campaign): void {
                DB::transaction(function () use ($campaign, $deviceTokens): void {
                    $rows = $deviceTokens->map(fn (FcmDeviceToken $deviceToken): array => [
                        'campaign_id' => $campaign->id,
                        'fcm_device_token_id' => $deviceToken->id,
                        'token_hash' => $deviceToken->token_hash,
                        'status' => 'pending',
                        'attempts' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all();

                    DB::table('fcm_notification_deliveries')->insertOrIgnore($rows);
                });
            });

        $campaign->refresh();
        $campaign->recipient_count = $campaign->deliveries()->count();

        if ($campaign->recipient_count === 0) {
            $campaign->status = 'failed';
            $campaign->started_at ??= now();
            $campaign->completed_at = now();
            $campaign->save();

            return;
        }

        $campaign->status = 'processing';
        $campaign->started_at ??= now();
        $campaign->save();

        FanoutFcmNotificationCampaign::dispatch($campaign->id)
            ->onConnection('database');
    }

    public function failed(Throwable $exception): void
    {
        DB::table('fcm_notification_deliveries')
            ->where('campaign_id', $this->campaignId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'error_code' => 'CAMPAIGN_BUILD_FAILED',
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
