<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BalanceLedgerEntry;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminDepositController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(Deposit::STATUSES)],
        ]);

        $deposits = Deposit::query()
            ->with('user:id,name,email')
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('deposit_id', 'like', '%'.$search.'%')
                        ->orWhere('transaction_reference', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($users) => $users->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.deposits.index', [
            'deposits' => $deposits,
            'counts' => [
                'all' => Deposit::count(),
                'pending' => Deposit::where('status', 'pending')->count(),
                'approved' => Deposit::where('status', 'approved')->count(),
                'rejected' => Deposit::where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function show(Deposit $deposit): View
    {
        return view('admin.deposits.show', [
            'deposit' => $deposit->load(['user', 'reviewer']),
            'audit' => AuditLog::query()
                ->where('auditable_type', Deposit::class)
                ->where('auditable_id', $deposit->id)
                ->with('actor')
                ->latest()
                ->get(),
            'ledgerEntry' => BalanceLedgerEntry::query()->where('deposit_id', $deposit->id)->first(),
        ]);
    }

    public function update(Request $request, Deposit $deposit): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $admin = $request->user('admin');
        $alreadyResolved = false;

        DB::transaction(function () use ($request, $deposit, $validated, $admin, &$alreadyResolved): void {
            $locked = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                if ($locked->status === $validated['status']) {
                    $alreadyResolved = true;

                    return;
                }

                abort(422, 'This deposit request has already been resolved.');
            }

            if ($validated['status'] === 'approved') {
                $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
                abort_if(
                    BalanceLedgerEntry::query()->where('deposit_id', $locked->id)->exists(),
                    409,
                    'A ledger credit already exists for this deposit.'
                );

                BalanceLedgerEntry::create([
                    'user_id' => $user->id,
                    'deposit_id' => $locked->id,
                    'actor_user_id' => $admin->id,
                    'entry_type' => 'credit',
                    'amount' => $locked->amount,
                ]);
                DB::table('users')->where('id', $user->id)->increment('balance', $locked->amount);
            }

            $locked->forceFill([
                'status' => $validated['status'],
                'rejection_reason' => $validated['status'] === 'rejected'
                    ? ($validated['rejection_reason'] ?? null)
                    : null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            AuditLog::create([
                'actor_user_id' => $admin->id,
                'subject_user_id' => $locked->user_id,
                'event' => 'admin.deposit_'.$validated['status'],
                'auditable_type' => Deposit::class,
                'auditable_id' => $locked->id,
                'metadata' => [
                    'amount' => $locked->amount,
                    'transaction_reference' => $locked->transaction_reference,
                    'rejection_reason' => $locked->rejection_reason,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with(
            'status',
            $alreadyResolved ? 'This deposit was already processed; no additional balance change was made.' : 'Deposit request updated.'
        );
    }

    public function proof(Deposit $deposit): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($deposit->proof_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($deposit->proof_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($deposit->proof_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
