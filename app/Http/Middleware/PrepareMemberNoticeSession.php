<?php

namespace App\Http\Middleware;

use App\Support\MemberNoticePrompt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrepareMemberNoticeSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            ! $request->session()->has('admin_impersonation')
            && $request->user('web')?->role === 'user'
        ) {
            MemberNoticePrompt::ensure($request);
        }

        return $next($request);
    }
}
