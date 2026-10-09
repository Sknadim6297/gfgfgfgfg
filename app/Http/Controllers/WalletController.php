<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\WithdrawalRequest;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(Deposit::STATUSES)],
            'withdrawal_status' => ['nullable', Rule::in(WithdrawalRequest::STATUSES)],
        ]);

        $deposits = $user->deposits()
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('deposit_id', 'like', '%'.$search.'%')
                        ->orWhere('transaction_reference', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('submitted_at')
            ->paginate(10)
            ->withQueryString();

        $withdrawals = $user->withdrawalRequests()
            ->when($filters['withdrawal_status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('requested_at')
            ->paginate(10, ['*'], 'withdrawal_page')
            ->withQueryString();
        $referralCredits = $user->inrLedgerEntries()
            ->where('source_type', 'referral_commission')
            ->where('entry_type', 'credit')
            ->whereHas('referralCommission', fn ($query) => $query->where('status', 'credited'))
            ->with('referralCommission')
            ->latest('created_at')
            ->paginate(10, ['*'], 'referral_ledger_page')
            ->withQueryString();
        $canWithdraw = Decimal::compare((string) $user->inr_balance, '0', 2) > 0;

        return view('pages.wallet', [
            'user' => $user,
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'referralCredits' => $referralCredits,
            'canWithdraw' => $canWithdraw,
        ]);
    }
}
