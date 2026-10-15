<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BalanceLedgerEntry;
use App\Models\Deposit;
use App\Models\User;
use App\Support\Decimal;
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
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 8), 0) as approved_amount', ['approved'])
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
        $audit = AuditLog::query()
            ->where('auditable_type', Deposit::class)
            ->where('auditable_id', $deposit->id)
            ->with('actor')
            ->latest()
            ->get();

        return view('admin.deposits.show', [
            'deposit' => $deposit->load(['user', 'reviewer']),
            'audit' => $audit,
            'transactionHashSubmittedAt' => $audit->firstWhere('event', 'deposit.transaction_hash_submitted')?->created_at,
            'ledgerEntry' => BalanceLedgerEntry::query()->where('deposit_id', $deposit->id)->first(),
        ]);
    }

    public function update(Request $request, Deposit $deposit): RedirectResponse
    {
        $rules = [
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:2000'],
        ];
        if ($deposit->network_name === 'BEP20') {
            $rules += [
                'verification_checks' => ['exclude_unless:status,approved', 'required', 'array'],
                'verification_checks.network' => ['exclude_unless:status,approved', 'required', 'accepted'],
                'verification_checks.receipt' => ['exclude_unless:status,approved', 'required', 'accepted'],
                'verification_checks.contract' => ['exclude_unless:status,approved', 'required', 'accepted'],
                'verification_checks.recipient' => ['exclude_unless:status,approved', 'required', 'accepted'],
                'verification_checks.amount' => ['exclude_unless:status,approved', 'required', 'accepted'],
                'verification_checks.confirmations' => ['exclude_unless:status,approved', 'required', 'accepted'],
                'verification_checks.unique_transfer' => ['exclude_unless:status,approved', 'required', 'accepted'],
                'verified_network' => ['exclude_unless:status,approved', 'required', 'in:BEP20'],
                'verified_token_contract_address' => ['exclude_unless:status,approved', 'required', 'string', 'regex:/\A0x[a-fA-F0-9]{40}\z/'],
                'verified_recipient_address' => ['exclude_unless:status,approved', 'required', 'string', 'regex:/\A0x[a-fA-F0-9]{40}\z/'],
                'verified_amount' => ['exclude_unless:status,approved', 'required', 'string', 'regex:/\A(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?\z/'],
                'transaction_event_index' => ['exclude_unless:status,approved', 'required', 'integer', 'min:0'],
                'blockchain_confirmations' => ['exclude_unless:status,approved', 'required', 'integer', 'min:1'],
            ];
        }
        $validated = $request->validate($rules);
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

            $verificationMetadata = null;
            if ($validated['status'] === 'approved') {
                if ($locked->network_name === 'BEP20') {
                    $verificationMetadata = $this->validateBep20Verification($locked, $validated, $admin->id);
                }

                $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
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

                if ($verificationMetadata !== null) {
                    $locked->forceFill([
                        'transaction_event_index' => $validated['transaction_event_index'],
                        'blockchain_confirmations' => $validated['blockchain_confirmations'],
                        'blockchain_verified_at' => now(),
                    ]);
                }
            }

            $locked->forceFill([
                'status' => $validated['status'],
                'rejection_reason' => $validated['status'] === 'rejected'
                    ? $validated['rejection_reason']
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
                    'transaction_hash' => $locked->transaction_hash,
                    'manual_verification' => $verificationMetadata,
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

    private function validateBep20Verification(Deposit $deposit, array $validated, int $adminId): array
    {
        if (! is_string($deposit->transaction_hash)
            || preg_match('/\A0x[a-fA-F0-9]{64}\z/', $deposit->transaction_hash) !== 1) {
            throw ValidationException::withMessages([
                'transaction_hash' => 'A valid customer-submitted transaction hash is required before approval.',
            ]);
        }

        $checks = $validated['verification_checks'] ?? [];
        foreach (['network', 'receipt', 'contract', 'recipient', 'amount', 'confirmations', 'unique_transfer'] as $check) {
            if (! in_array($checks[$check] ?? null, [true, 1, '1', 'on', 'yes'], true)) {
                throw ValidationException::withMessages([
                    'verification_checks' => 'Confirm every manual blockchain verification item before approving.',
                ]);
            }
        }

        $trustedContract = config('services.bep20.usdt_contract_address');
        if (! is_string($trustedContract)
            || preg_match('/\A0x[a-fA-F0-9]{40}\z/', $trustedContract) !== 1
            || ! is_string($deposit->token_contract_address)
            || ! hash_equals(strtolower($trustedContract), strtolower($deposit->token_contract_address))
            || ! hash_equals(strtolower($deposit->token_contract_address), strtolower($validated['verified_token_contract_address']))) {
            throw ValidationException::withMessages([
                'verified_token_contract_address' => 'The verified contract must match both the deposit snapshot and trusted server configuration.',
            ]);
        }

        if (! is_string($deposit->receiving_wallet_address)
            || ! hash_equals(strtolower($deposit->receiving_wallet_address), strtolower($validated['verified_recipient_address']))) {
            throw ValidationException::withMessages([
                'verified_recipient_address' => 'The verified recipient must match the deposit’s original receiving wallet.',
            ]);
        }

        if (Decimal::compare($validated['verified_amount'], (string) $deposit->amount, 8) !== 0) {
            throw ValidationException::withMessages([
                'verified_amount' => 'The verified token transfer amount must match the requested deposit amount exactly.',
            ]);
        }

        return [
            'network' => 'BNB Smart Chain mainnet (BEP20)',
            'receipt_successful' => true,
            'contract_address' => strtolower($validated['verified_token_contract_address']),
            'recipient_address' => strtolower($validated['verified_recipient_address']),
            'verified_amount' => $validated['verified_amount'],
            'transaction_event_index' => (int) $validated['transaction_event_index'],
            'blockchain_confirmations' => (int) $validated['blockchain_confirmations'],
            'required_confirmations_satisfied' => true,
            'transfer_event_not_previously_credited' => true,
            'verified_by_admin_id' => $adminId,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    private function snapshotAvailable(?string $path, string $kind): bool
    {
        $managed = is_string($path)
            && preg_match('~\Adeposits/snapshots/[A-Za-z0-9-]+-'.$kind.'\.(?:png|jpe?g|webp)\z~D', $path) === 1;

        return $managed && Storage::disk('local')->exists($path);
    }
}
