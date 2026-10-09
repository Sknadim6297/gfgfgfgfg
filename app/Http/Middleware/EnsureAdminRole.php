<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check() || Auth::guard('admin')->user()?->role !== 'admin') {
            Auth::guard('admin')->logout();

            return redirect()->route('admin.login');
        }

        if (Auth::guard('admin')->user()?->account_status !== 'active') {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors([
                'email' => 'This admin account is not active.',
            ]);
        }

        return $next($request);
    }
}
