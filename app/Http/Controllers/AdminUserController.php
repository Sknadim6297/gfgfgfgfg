<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'blocked'])],
            'bank_status' => ['nullable', Rule::in(User::BANK_VERIFICATION_STATUSES)],
        ]);

        $users = User::query()
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('mobile', 'like', '%'.$search.'%');
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('account_status', $status))
            ->when($validated['bank_status'] ?? null, fn ($query, string $status) => $query->where('bank_verification_status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'stats' => [
                'total' => User::count(),
                'active' => User::where('account_status', 'active')->count(),
                'suspended' => User::where('account_status', 'suspended')->count(),
                'blocked' => User::where('account_status', 'blocked')->count(),
            ],
        ]);
    }

    public function show(User $user): View
    {
        return view('admin.users.show', [
            'user' => $user,
            'bankVerificationStatus' => $user->bankVerificationStatus(),
            'exchanges' => $user->exchangeRequests()->latest()->paginate(10),
            'activity' => AuditLog::with('actor')->where('subject_user_id', $user->id)->latest()->limit(30)->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'account_status' => ['required', Rule::in(['active', 'suspended', 'blocked'])],
            'role' => ['required', Rule::in(['user', 'admin'])],
        ]);
        abort_if($user->id === $request->user('admin')->id, 403);

        DB::transaction(function () use ($request, $user, $validated): void {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $removingActiveAdmin = $lockedUser->role === 'admin'
                && $lockedUser->account_status === 'active'
                && ($validated['role'] !== 'admin' || $validated['account_status'] !== 'active');

            if ($removingActiveAdmin) {
                $activeAdminIds = User::where('role', 'admin')
                    ->where('account_status', 'active')
                    ->lockForUpdate()
                    ->pluck('id');
                abort_if($activeAdminIds->count() <= 1, 422, 'At least one other active admin is required.');
            }

            $before = ['role' => $lockedUser->role, 'account_status' => $lockedUser->account_status];
            $promotingToAdmin = $validated['role'] === 'admin' && $lockedUser->role !== 'admin';
            $lockedUser->forceFill($validated);
            if ($promotingToAdmin) {
                $lockedUser->totp_enabled = false;
                $lockedUser->totp_secret = null;
                $lockedUser->totp_last_counter = null;
            }
            $lockedUser->save();

            AuditLog::create([
                'actor_user_id' => $request->user('admin')->id,
                'subject_user_id' => $lockedUser->id,
                'event' => 'admin.user_access_updated',
                'metadata' => ['before' => $before, 'after' => $validated],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with('status', 'User access settings updated.');
    }

    public function verifyBank(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'user', 404);

        DB::transaction(function () use ($request, $user): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedUser->hasCompleteBankDetails(), 422, 'Complete bank details are required before verification.');
            abort_unless($lockedUser->bankVerificationStatus() === 'pending', 422, 'Only pending bank details can be verified.');

            $lockedUser->forceFill([
                'bank_verification_status' => 'verified',
                'bank_verification_reason' => null,
                'bank_verified_at' => now(),
                'bank_reviewed_by_user_id' => $request->user('admin')->id,
            ])->save();

            $this->auditBankDecision($request, $lockedUser, 'admin.bank_details_verified', []);
        });

        return back()->with('status', 'Bank details verified.');
    }

    public function rejectBank(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'user', 404);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $user, $validated): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedUser->hasCompleteBankDetails(), 422, 'Complete bank details are required before review.');
            abort_unless($lockedUser->bankVerificationStatus() === 'pending', 422, 'Only pending bank details can be rejected.');

            $lockedUser->forceFill([
                'bank_verification_status' => 'rejected',
                'bank_verification_reason' => trim($validated['reason']),
                'bank_verified_at' => null,
                'bank_reviewed_by_user_id' => $request->user('admin')->id,
            ])->save();

            $this->auditBankDecision($request, $lockedUser, 'admin.bank_details_rejected', [
                'reason' => trim($validated['reason']),
            ]);
        });

        return back()->with('status', 'Bank details rejected.');
    }

    private function auditBankDecision(Request $request, User $user, string $event, array $metadata): void
    {
        AuditLog::create([
            'actor_user_id' => $request->user('admin')->id,
            'subject_user_id' => $user->id,
            'event' => $event,
            'metadata' => ['verification_status' => $user->bankVerificationStatus(), ...$metadata],
            'ip_address' => $request->ip(),
        ]);
    }
}
