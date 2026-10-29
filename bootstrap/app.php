<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureAdminSessionFresh;
use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Http\Middleware\EnsureBankVerificationPermission;
use App\Http\Middleware\EnsureImpersonationIsReadOnly;
use App\Http\Middleware\IdempotentFinancialThrottle;
use App\Http\Middleware\PrepareMemberNoticeSession;
use App\Http\Middleware\ScopedThrottleRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trimStrings(except: ['transaction_hash']);
        $middleware->alias([
            'active.account' => EnsureActiveAccount::class,
            'bank.verification' => EnsureBankVerificationPermission::class,
            'admin.session' => EnsureAdminSessionFresh::class,
            'admin.role' => EnsureAdminRole::class,
            'admin.2fa' => EnsureAdminTwoFactor::class,
            'impersonation.readonly' => EnsureImpersonationIsReadOnly::class,
            'member.notice-session' => PrepareMemberNoticeSession::class,
            'financial.throttle' => IdempotentFinancialThrottle::class,
            'route.throttle' => ScopedThrottleRequests::class,
        ]);
        $middleware->redirectGuestsTo(
            fn (Request $request): string => $request->is('admin', 'admin/*')
                ? route('admin.login')
                : route('login')
        );
        $middleware->redirectUsersTo(
            fn (Request $request): string => $request->is('admin', 'admin/*')
                ? route('admin.dashboard')
                : route('home')
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            $headers = $exception->getHeaders();
            $retryAfter = (int) ($headers['Retry-After'] ?? 60);
            $message = 'You have made too many requests. Please wait before trying again.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'retry_after' => $retryAfter,
                ], 429, $headers);
            }

            return response()->view('errors.429', [
                'retryAfter' => $retryAfter,
            ], 429, $headers);
        });
    })->create();
