<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BalanceLedgerEntry;
use App\Models\DemoUsdtLedgerEntry;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminDepositController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(Deposit::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $summary = Deposit::query()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count', ['pending'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved_count', ['approved'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected_count', ['rejected'])
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN status = ? AND is_demo = ? THEN amount ELSE 0 END), 8), 0) as approved_amount', ['approved', false])
            ->first();

        $deposits = Deposit::query()
            ->with('user:id,name,email,customer_id')
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('deposit_id', 'like', '%'.$search.'%')
                        ->orWhere('transaction_reference', 'like', '%'.$search.'%')
                        ->orWhere('transaction_hash', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($users) => $users
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('customer_id', 'like', '%'.$search.'%'));
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('submitted_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('submitted_at', '<=', $date))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.deposits.index', [
            'deposits' => $deposits,
            'summary' => $summary,
        ]);
    }

    public function show(Deposit $deposit): View
    {
        return view('admin.deposits.show', [
            'deposit' => $deposit->load('user'),
        ]);
    }

    public function update(Request $request, Deposit $deposit): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]);
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

                if ($locked->is_demo) {
                    abort_unless(
                        config('deposits.demo_approval_enabled') === true,
                        403,
                        'Demo deposit approval is disabled. Enable it explicitly before approving demo deposits.'
                    );
                    abort_if(
                        DemoUsdtLedgerEntry::query()->where('deposit_id', $locked->id)->exists(),
                        409,
                        'A demo wallet credit already exists for this deposit.'
                    );

                    DemoUsdtLedgerEntry::create([
                        'user_id' => $user->id,
                        'deposit_id' => $locked->id,
                        'actor_user_id' => $admin->id,
                        'amount' => $locked->amount,
                    ]);
                    DB::table('users')->where('id', $user->id)->increment('demo_usdt_balance', $locked->amount);
                } else {
                    if ($locked->blockchain_verified_at === null
                        || ! is_string($locked->blockchain_verification_source)
                        || trim($locked->blockchain_verification_source) === '') {
                        throw ValidationException::withMessages([
                            'status' => 'Real USDT deposits require independent blockchain verification before approval.',
                        ]);
                    }

                    abort_if(
                        BalanceLedgerEntry::query()->where('deposit_id', $locked->id)->exists(),
                        409,
                        'A ledger credit already exists for this deposit.'
                    );
                    if ($locked->transaction_hash
                        && Deposit::query()->where('transaction_hash', $locked->transaction_hash)->where('id', '!=', $locked->id)->exists()) {
                        abort(409, 'This blockchain transaction has already been claimed by another deposit.');
                    }

                    BalanceLedgerEntry::create([
                        'user_id' => $user->id,
                        'deposit_id' => $locked->id,
                        'actor_user_id' => $admin->id,
                        'entry_type' => 'credit',
                        'amount' => $locked->amount,
                    ]);
                    DB::table('users')->where('id', $user->id)->increment('balance', $locked->amount);
                }
            }

            $locked->forceFill([
                'status' => $validated['status'],
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
                    'is_demo' => $locked->is_demo,
                    'credited_wallet' => $validated['status'] === 'approved'
                        ? ($locked->is_demo ? 'demo_usdt' : 'usdt')
                        : null,
                    'transaction_reference' => $locked->transaction_reference,
                    'transaction_hash' => $locked->transaction_hash,
                    'transaction_verification' => $locked->is_demo ? 'not_verified_demo' : null,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with(
            'status',
            $alreadyResolved
                ? 'This deposit was already processed; no additional balance change was made.'
                : ($validated['status'] === 'approved' ? 'Deposit approved successfully.' : 'Deposit rejected successfully.')
        );
    }

    public function proof(Deposit $deposit): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($deposit->proof_path && Storage::disk('local')->exists($deposit->proof_path), 404);

        return Storage::disk('local')->response($deposit->proof_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function image(Deposit $deposit, string $image): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $path = $image === 'logo' ? $deposit->network_logo_path : $deposit->qr_snapshot_path;
        abort_unless($this->snapshotAvailable($path, $image), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function snapshotAvailable(?string $path, string $kind): bool
    {
        $managed = is_string($path)
            && preg_match('~\Adeposits/snapshots/[A-Za-z0-9-]+-'.$kind.'\.(?:png|jpe?g|webp)\z~D', $path) === 1;

        return $managed && Storage::disk('local')->exists($path);
    }
}
