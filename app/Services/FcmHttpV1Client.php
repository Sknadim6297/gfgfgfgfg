<?php

namespace App\Services;

use App\Models\FcmNotificationCampaign;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FcmHttpV1Client
{
    public function __construct(private readonly FirebaseAccessTokenProvider $accessTokens) {}

    public function send(FcmNotificationCampaign $campaign, string $token): string
    {
        $projectId = config('services.firebase.project_id');
        $appUrl = config('app.url');
        $appHost = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;
        $appPort = is_string($appUrl) ? parse_url($appUrl, PHP_URL_PORT) : null;
        if (! is_string($projectId) || $projectId === '' || ! is_string($appHost) || $appHost === '') {
            throw new FcmSendException('CONFIGURATION_INVALID', false);
        }

        $origin = 'https://'.$appHost.($appPort ? ':'.$appPort : '');
        $destination = $campaign->destination_url ?: '/profile';
        $webLink = str_starts_with($destination, '/') && ! str_starts_with($destination, '//')
            ? $origin.$destination
            : $destination;
        $iconUrl = $origin.'/icons/icon-192.png';

        try {
            $response = Http::acceptJson()
                ->withToken($this->accessTokens->accessToken())
                ->timeout(10)
                ->post(
                    'https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send',
                    [
                        'message' => [
                            'token' => $token,
                            'webpush' => [
                                'notification' => [
                                    'title' => $campaign->title,
                                    'body' => $campaign->message,
                                    'icon' => $iconUrl,
                                    'tag' => 'quickpay-push-'.$campaign->id,
                                ],
                            ],
                            'data' => [
                                'title' => $campaign->title,
                                'body' => $campaign->message,
                                'url' => $webLink,
                                'icon' => $iconUrl,
                                'tag' => 'quickpay-push-'.$campaign->id,
                            ],
                        ],
                    ],
                );
        } catch (ConnectionException) {
            throw new FcmSendException('FCM_CONNECTION_FAILED', true);
        } catch (RuntimeException) {
            throw new FcmSendException('FCM_AUTH_OR_CONFIGURATION_FAILED', false);
        }

        if ($response->successful()) {
            $name = $response->json('name');
            if (! is_string($name) || $name === '') {
                throw new FcmSendException('FCM_INVALID_RESPONSE', true);
            }

            return $name;
        }

        $errorCode = collect($response->json('error.details', []))
            ->first(fn ($detail) => is_array($detail) && ($detail['@type'] ?? null) === 'type.googleapis.com/google.firebase.fcm.v1.FcmError')['errorCode'] ?? null;

        if ($errorCode === 'UNREGISTERED') {
            throw new FcmSendException('UNREGISTERED', false, true);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new FcmSendException('FCM_TEMPORARY_FAILURE', true);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new FcmSendException('FCM_AUTHORIZATION_FAILED', false);
        }

        throw new FcmSendException('FCM_REQUEST_REJECTED', false);
    }
}
