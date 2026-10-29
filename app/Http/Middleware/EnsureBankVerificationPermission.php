<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBankVerificationPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        abort_unless(
            $admin?->role === 'admin'
            && $admin->account_status === 'active',
            403,
        );

        return $next($request);
    }
}
