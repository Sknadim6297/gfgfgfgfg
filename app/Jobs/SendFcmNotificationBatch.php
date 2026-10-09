<?php

namespace App\Jobs;

use App\Models\FcmNotificationCampaign;
use App\Models\FcmNotificationDelivery;
use App\Services\FcmHttpV1Client;
use App\Services\FcmSendException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SendFcmNotificationBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public array $backoff = [10, 30, 60, 120];

    /**
     * @param  list<int>  $deliveryIds
     */
    public function __construct(
        public readonly int $campaignId,
        public readonly array $deliveryIds,
    ) {}

    public function handle(FcmHttpV1Client $fcm): void
    {
        $campaign = FcmNotificationCampaign::find($this->campaignId);
        if (! $campaign || $campaign->status === 'failed') {
            return;
        }

        foreach ($this->deliveryIds as $deliveryId) {
            $delivery = $this->claimDelivery((int) $deliveryId);
            if (! $delivery) {
                continue;
            }

            $deviceToken = $delivery->deviceToken;
            if (! $deviceToken) {
                $this->markFailed($delivery->id, 'TOKEN_NO_LONGER_REGISTERED');
                $campaign->refreshProgress();

                continue;
            }

            try {
                $messageName = $fcm->send($campaign, $deviceToken->token);
            } catch (FcmSendException $exception) {
                if ($exception->retryable && $this->attempts() < $this->tries) {
                    $this->returnToPending($delivery->id);
                    throw new RuntimeException('Temporary Firebase delivery failure.');
                }

                $this->markFailed($delivery->id, $exception->failureCode);
                if ($exception->invalidToken) {
                    $deviceToken->delete();
                }
                $campaign->refreshProgress();

                continue;
            } catch (Throwable) {
                $this->returnToPending($delivery->id);
                throw new RuntimeException('Firebase delivery failed unexpectedly.');
            }

            FcmNotificationDelivery::query()->whereKey($delivery->id)->update([
                'status' => 'accepted',
                'fcm_message_name' => $messageName,
                'accepted_at' => now(),
                'processing_at' => null,
                'error_code' => null,
                'updated_at' => now(),
            ]);
            $campaign->refreshProgress();
        }
    }

    public function failed(Throwable $exception): void
    {
        FcmNotificationDelivery::query()
            ->whereIn('id', $this->deliveryIds)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status' => 'failed',
                'error_code' => 'RETRIES_EXHAUSTED',
                'failed_at' => now(),
                'processing_at' => null,
                'updated_at' => now(),
            ]);

        FcmNotificationCampaign::find($this->campaignId)?->refreshProgress();
    }

    private function claimDelivery(int $deliveryId): ?FcmNotificationDelivery
    {
        return DB::transaction(function () use ($deliveryId): ?FcmNotificationDelivery {
            $delivery = FcmNotificationDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if (! $delivery) {
                return null;
            }

            $isPending = $delivery->status === 'pending';
            $leaseExpired = $delivery->status === 'processing'
                && $delivery->processing_at?->lt(now()->subMinutes(3));
            if (! $isPending && ! $leaseExpired) {
                return null;
            }

            $delivery->status = 'processing';
            $delivery->attempts++;
            $delivery->processing_at = now();
            $delivery->save();

            return $delivery;
        });
    }

    private function returnToPending(int $deliveryId): void
    {
        FcmNotificationDelivery::query()->whereKey($deliveryId)->update([
            'status' => 'pending',
            'processing_at' => null,
            'updated_at' => now(),
        ]);
    }

    private function markFailed(int $deliveryId, string $errorCode): void
    {
        FcmNotificationDelivery::query()->whereKey($deliveryId)->update([
            'status' => 'failed',
            'error_code' => $errorCode,
            'failed_at' => now(),
            'processing_at' => null,
            'updated_at' => now(),
        ]);
    }
}
