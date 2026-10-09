<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSessionFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            return $next($request);
        }

        $storedHash = $request->session()->get('admin_password_hash');

        if ($storedHash && ! hash_equals($admin->getAuthPassword(), $storedHash)) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login');
        }

        $request->session()->put('admin_password_hash', $admin->getAuthPassword());

        return tap($next($request), function () use ($request): void {
            $currentAdmin = Auth::guard('admin')->user();

            if ($currentAdmin) {
                $request->session()->put('admin_password_hash', $currentAdmin->getAuthPassword());
            }
        });
    }
}
