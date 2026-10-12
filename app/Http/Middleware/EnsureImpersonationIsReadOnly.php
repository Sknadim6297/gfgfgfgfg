<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureImpersonationIsReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->session()->has('admin_impersonation')
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
        ) {
            abort(403, 'Member actions are disabled during admin impersonation.');
        }

        return $next($request);
    }
}
