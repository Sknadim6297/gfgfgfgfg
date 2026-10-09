<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin?->totp_enabled || $request->session()->get('admin_2fa_verified') !== true) {
            return redirect()->route($admin?->totp_enabled ? 'admin.2fa.challenge' : 'admin.2fa.setup');
        }

        return $next($request);
    }
}
