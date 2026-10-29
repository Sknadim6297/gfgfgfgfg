<?php

namespace App\Http\Middleware;

use App\Models\Deposit;
use App\Models\ExchangeRequest;
use App\Models\InternalUsdtTransfer;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdempotentFinancialThrottle
{
    public function handle(
        Request $request,
        Closure $next,
        string $submissionType,
        int $maxAttempts = 60,
        int $decayMinutes = 1,
    ): Response {
        $user = $request->user();
        $submissionKey = $request->input('submission_key');

        if (
            $user instanceof User
            && is_string($submissionKey)
            && Str::isUuid($submissionKey)
            && $this->submissionExists($submissionType, $user, $submissionKey)
        ) {
            return app(\Illuminate\Routing\Middleware\ThrottleRequests::class)->handle(
                $request,
                $next,
                $maxAttempts,
                $decayMinutes,
                'financial:'.$submissionType.':replay:',
            );
        }

        return app(\Illuminate\Routing\Middleware\ThrottleRequests::class)->handle(
            $request,
            $next,
            $maxAttempts,
            $decayMinutes,
            'financial:'.$submissionType.':',
        );
    }

    private function submissionExists(string $submissionType, User $user, string $submissionKey): bool
    {
        return match ($submissionType) {
            'sell' => ExchangeRequest::query()
                ->where('user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->exists(),
            'deposit' => Deposit::query()
                ->where('user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->exists(),
            'withdrawal' => WithdrawalRequest::query()
                ->where('user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->exists(),
            'transfer' => InternalUsdtTransfer::query()
                ->where('sender_user_id', $user->id)
                ->where('submission_key', $submissionKey)
                ->exists(),
            default => throw new \InvalidArgumentException('Unknown financial submission type.'),
        };
    }
}
