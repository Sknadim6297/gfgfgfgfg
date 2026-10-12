<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScopedThrottleRequests
{
    public function handle(
        Request $request,
        Closure $next,
        int $maxAttempts = 60,
        int $decayMinutes = 1,
    ): Response {
        $route = $request->route();
        $scope = $route?->getName() ?? $route?->uri() ?? $request->path();

        return app(\Illuminate\Routing\Middleware\ThrottleRequests::class)->handle(
            $request,
            $next,
            $maxAttempts,
            $decayMinutes,
            'route:'.$scope.':',
        );
    }
}
