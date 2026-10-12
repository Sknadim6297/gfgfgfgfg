<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InrLedgerEntry;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\ReferralCommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminWithdrawalController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(WithdrawalRequest::STATUSES)],
        ]);

        $summary = WithdrawalRequest::query()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('COALESCE(ROUND(SUM(amount), 2), 0) as total_amount')
            ->selectRaw(
                'COALESCE(ROUND(SUM(CASE WHEN status IN (?, ?) THEN amount ELSE 0 END), 2), 0) as pending_amount',
                ['pending', 'processing']
            )
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 2), 0) as completed_amount', ['completed'])
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 2), 0) as rejected_amount', ['rejected'])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as pending_count', ['pending', 'processing'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_count', ['completed'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected_count', ['rejected'])
            ->first();

        $withdrawals = WithdrawalRequest::with('user:id,name,email,customer_id')
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('request_reference', 'like', '%'.$search.'%')
                        ->orWhere('transaction_reference', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($users) => $users->where('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('requested_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.withdrawals.index', [
            'withdrawals' => $withdrawals,
            'summary' => $summary,
        ]);
    }

    public function show(WithdrawalRequest $withdrawalRequest): View
    {
        return view('admin.withdrawals.show', [
            'withdrawal' => $withdrawalRequest->load(['user', 'reviewer']),
            'audit' => AuditLog::query()
                ->where('auditable_type', WithdrawalRequest::class)
                ->where('auditable_id', $withdrawalRequest->id)
                ->with('actor')
                ->latest()
                ->get(),
        ]);
    }

    public function update(
        Request $request,
        WithdrawalRequest $withdrawalRequest,
        ReferralCommissionService $referralCommissions,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in(WithdrawalRequest::STATUSES)],
            'transaction_reference' => [
                'required_if:status,completed',
                'nullable',
                'string',
                'max:150',
                Rule::unique('withdrawal_requests', 'transaction_reference')->ignore($withdrawalRequest->id),
            ],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'min:3', 'max:500'],
        ]);
        $admin = $request->user('admin');

        DB::transaction(function () use ($request, $withdrawalRequest, $validated, $admin, $referralCommissions): void {
            User::query()
                ->whereKey($withdrawalRequest->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $withdrawal = WithdrawalRequest::query()->whereKey($withdrawalRequest->id)->lockForUpdate()->firstOrFail();
            $allowed = match ($withdrawal->status) {
                'pending' => ['processing', 'rejected'],
                'processing' => ['completed', 'rejected'],
                default => [],
            };
            abort_unless(in_array($validated['status'], $allowed, true), 422, 'This withdrawal status transition is not allowed.');

            $rateSnapshot = null;
            if ($validated['status'] === 'completed') {
                $rateSnapshot = $referralCommissions->activeBaseRate();
                abort_if(
                    $rateSnapshot === null,
                    422,
                    'An active Base Rate is required to complete this withdrawal.'
                );
            }

            $before = [
                'status' => $withdrawal->status,
                'transaction_reference' => $withdrawal->transaction_reference,
            ];

            if ($validated['status'] === 'completed' && $withdrawal->inrLedgerEntries()->where('entry_type', 'hold')->exists()) {
                InrLedgerEntry::create([
                    'user_id' => $withdrawal->user_id,
                    'actor_user_id' => $admin->id,
                    'source_type' => 'withdrawal_request',
                    'source_id' => $withdrawal->id,
                    'entry_type' => 'capture',
                    'amount' => $withdrawal->amount,
                ]);
            }

            if ($validated['status'] === 'rejected') {
                $user = User::query()->whereKey($withdrawal->user_id)->lockForUpdate()->firstOrFail();
                $held = $withdrawal->inrLedgerEntries()->where('entry_type', 'hold')->exists();
                $releaseType = $held ? 'release' : 'credit';
                abort_if($withdrawal->inrLedgerEntries()->where('entry_type', $releaseType)->exists(), 409, 'This withdrawal has already been released.');
                InrLedgerEntry::create([
                    'user_id' => $user->id,
                    'actor_user_id' => $admin->id,
                    'source_type' => 'withdrawal_request',
                    'source_id' => $withdrawal->id,
                    'entry_type' => $releaseType,
                    'amount' => $withdrawal->amount,
                ]);
                DB::table('users')->where('id', $user->id)->increment('inr_balance', $withdrawal->amount);
            }

            $withdrawal->forceFill([
                'status' => $validated['status'],
                'transaction_reference' => $validated['transaction_reference'] ?? null,
                'admin_notes' => $validated['admin_notes'] ?? null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
                'completed_at' => $validated['status'] === 'completed' ? now() : $withdrawal->completed_at,
                'rejection_reason' => $validated['status'] === 'rejected' ? $validated['rejection_reason'] : null,
                ...($rateSnapshot === null ? [] : ['usdt_inr_rate_snapshot' => $rateSnapshot]),
            ])->save();

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'subject_user_id' => $withdrawal->user_id,
                'event' => 'admin.withdrawal_'.$validated['status'],
                'auditable_type' => WithdrawalRequest::class,
                'auditable_id' => $withdrawal->id,
                'metadata' => [
                    'before' => $before,
                    'after' => [
                        'status' => $withdrawal->status,
                        'transaction_reference' => $withdrawal->transaction_reference,
                        'rejection_reason' => $withdrawal->rejection_reason,
                        'usdt_inr_rate_snapshot' => $withdrawal->usdt_inr_rate_snapshot,
                    ],
                    'amount' => $withdrawal->amount,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with('status', 'Withdrawal request updated. External payout execution is not automated by this system.');
    }
}
