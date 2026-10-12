<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\ExchangeRequest;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        $customerBalances = User::query()
            ->where('role', 'user')
            ->selectRaw('COALESCE(ROUND(SUM(balance), 8), 0) as usd_balance')
            ->selectRaw('COALESCE(ROUND(SUM(inr_balance), 2), 0) as inr_balance')
            ->first();

        return view('admin.dashboard', [
            'metrics' => [
                'users' => User::where('role', 'user')->count(),
                'active_users' => User::where('role', 'user')->where('account_status', 'active')->count(),
                'suspended_users' => User::where('role', 'user')->whereIn('account_status', ['suspended', 'blocked'])->count(),
                'exchange_requests' => ExchangeRequest::count(),
                'pending_transactions' => ExchangeRequest::whereIn('status', ['pending', 'processing'])->count(),
                'completed_transactions' => ExchangeRequest::where('status', 'completed')->count(),
                'failed_transactions' => ExchangeRequest::where('status', 'rejected')->count(),
            ],
            'customerBalances' => $customerBalances,
            'financialTotals' => [
                'sell' => ExchangeRequest::query()
                    ->selectRaw('COALESCE(ROUND(SUM(usdt_amount), 8), 0) as usdt')
                    ->selectRaw('COALESCE(ROUND(SUM(inr_amount), 2), 0) as inr')
                    ->first(),
                'deposits' => Deposit::query()
                    ->selectRaw('COALESCE(ROUND(SUM(amount), 2), 0) as usd')
                    ->first(),
                'withdrawals' => WithdrawalRequest::query()
                    ->selectRaw('COALESCE(ROUND(SUM(amount), 2), 0) as inr')
                    ->first(),
            ],
            'recentActivity' => AuditLog::with(['actor', 'subject'])->latest()->limit(8)->get(),
        ]);
    }
}
