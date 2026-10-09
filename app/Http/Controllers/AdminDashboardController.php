<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExchangeRequest;
use App\Models\User;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'metrics' => [
                'users' => User::where('role', 'user')->count(),
                'active_users' => User::where('role', 'user')->where('account_status', 'active')->count(),
                'suspended_users' => User::where('role', 'user')->whereIn('account_status', ['suspended', 'blocked'])->count(),
                'pending_verifications' => User::where('role', 'user')->where('verification_status', 'pending')->count(),
                'exchange_requests' => ExchangeRequest::count(),
                'pending_transactions' => ExchangeRequest::whereIn('status', ['pending', 'processing'])->count(),
                'completed_transactions' => ExchangeRequest::where('status', 'completed')->count(),
                'failed_transactions' => ExchangeRequest::where('status', 'rejected')->count(),
            ],
            'recentActivity' => AuditLog::with(['actor', 'subject'])->latest()->limit(8)->get(),
        ]);
    }
}
