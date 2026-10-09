<?php

namespace Tests\Feature;

use App\Models\FcmDeviceToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FcmDeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $token = 'fcm-device-token-secret-1';

        $deviceToken = $user->fcmDeviceTokens()->create([
            'token' => $token,
            'device_label' => 'Android Chrome',
        ]);

        $this->assertSame($token, $deviceToken->fresh()->token);
        $this->assertNotSame($token, DB::table('fcm_device_tokens')->where('id', $deviceToken->id)->value('token'));
        $this->assertArrayNotHasKey('token', $deviceToken->toArray());
        $this->assertArrayNotHasKey('token_hash', $deviceToken->toArray());
        $this->assertSame(hash('sha256', $token), $deviceToken->token_hash);
    }

    public function test_token_hash_is_unique_and_duplicate_tokens_cannot_be_claimed_twice(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $token = 'fcm-device-token-unique';

        $firstUser->fcmDeviceTokens()->create(['token' => $token]);

        $this->expectException(QueryException::class);
        $secondUser->fcmDeviceTokens()->create(['token' => $token]);
    }

    public function test_user_and_token_relationships_support_multiple_devices(): void
    {
        $user = User::factory()->create();
        $user->fcmDeviceTokens()->create([
            'token' => 'fcm-device-token-phone',
            'device_label' => 'Phone',
        ]);
        $user->fcmDeviceTokens()->create([
            'token' => 'fcm-device-token-laptop',
            'device_label' => 'Laptop browser',
        ]);

        $tokens = $user->fcmDeviceTokens()->get();

        $this->assertCount(2, $tokens);
        $this->assertTrue($tokens->every(fn (FcmDeviceToken $token): bool => $token->user->is($user)));
        $this->assertSame($user->id, $tokens->first()->user_id);
    }

    public function test_changing_token_rotates_hash_and_token_can_be_reassigned_to_another_user(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $deviceToken = $firstUser->fcmDeviceTokens()->create([
            'token' => 'fcm-device-token-before-rotation',
        ]);
        $originalHash = $deviceToken->token_hash;

        $deviceToken->token = 'fcm-device-token-after-rotation';
        $deviceToken->save();
        $deviceToken->user()->associate($secondUser);
        $deviceToken->save();

        $this->assertNotSame($originalHash, $deviceToken->fresh()->token_hash);
        $this->assertSame('fcm-device-token-after-rotation', $deviceToken->fresh()->token);
        $this->assertTrue($deviceToken->fresh()->user->is($secondUser));
        $this->assertCount(0, $firstUser->fcmDeviceTokens()->get());
        $this->assertCount(1, $secondUser->fcmDeviceTokens()->get());
    }
}
