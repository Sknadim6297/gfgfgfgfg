<?php

namespace App\Http\Controllers;

use App\Models\FcmDeviceToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FcmDeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'previous_token' => ['nullable', 'string', 'max:4096'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ]);
        $tokenHash = FcmDeviceToken::hashToken($validated['token']);

        try {
            $registered = $this->registerToken($user, $validated, $tokenHash);
        } catch (QueryException $exception) {
            try {
                $deviceToken = FcmDeviceToken::query()->where('token_hash', $tokenHash)->first();
            } catch (QueryException $lookupException) {
                Log::error('FCM device token registration database lookup failed.', [
                    'user_id' => $user->id,
                    'exception_class' => $lookupException::class,
                ]);

                return response()->json(['message' => 'Notification registration is temporarily unavailable.'], 500);
            }

            if (! $deviceToken) {
                Log::error('FCM device token registration database write failed.', [
                    'user_id' => $user->id,
                    'exception_class' => $exception::class,
                ]);

                return response()->json(['message' => 'Notification registration is temporarily unavailable.'], 500);
            }

            if ((int) $deviceToken->user_id !== (int) $user->id) {
                return response()->json(['message' => 'This browser could not be registered.'], 409);
            }

            try {
                $registered = $this->registerToken($user, $validated, $tokenHash);
            } catch (QueryException $retryException) {
                Log::error('FCM device token registration retry failed.', [
                    'user_id' => $user->id,
                    'exception_class' => $retryException::class,
                ]);

                return response()->json(['message' => 'Notification registration is temporarily unavailable.'], 500);
            }
        }

        if (! $registered) {
            return response()->json(['message' => 'This browser could not be registered.'], 409);
        }

        return response()->json(['status' => 'registered']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $user->fcmDeviceTokens()
            ->where('token_hash', FcmDeviceToken::hashToken($validated['token']))
            ->delete();

        return response()->json(['status' => 'unregistered']);
    }

    /**
     * @param  array{token: string, previous_token?: string|null, device_label?: string|null}  $validated
     */
    private function registerToken(User $user, array $validated, string $tokenHash): bool
    {
        return DB::transaction(function () use ($user, $validated, $tokenHash): bool {
            $deviceToken = FcmDeviceToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($deviceToken && (int) $deviceToken->user_id !== (int) $user->id) {
                return false;
            }

            if ($deviceToken) {
                $deviceToken->fill([
                    'device_label' => $validated['device_label'] ?? null,
                    'last_used_at' => now(),
                ])->save();
            } else {
                $user->fcmDeviceTokens()->create([
                    'token' => $validated['token'],
                    'device_label' => $validated['device_label'] ?? null,
                    'last_used_at' => now(),
                ]);
            }

            $previousToken = $validated['previous_token'] ?? null;

            if (is_string($previousToken) && ! hash_equals($previousToken, $validated['token'])) {
                $user->fcmDeviceTokens()
                    ->where('token_hash', FcmDeviceToken::hashToken($previousToken))
                    ->delete();
            }

            return true;
        });
    }
}
