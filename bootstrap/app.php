<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureAdminSessionFresh;
use App\Http\Middleware\EnsureAdminTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        $middleware->alias([
            'active.account' => EnsureActiveAccount::class,
            'admin.session' => EnsureAdminSessionFresh::class,
            'admin.role' => EnsureAdminRole::class,
            'admin.2fa' => EnsureAdminTwoFactor::class,
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
        //
    })->create();
