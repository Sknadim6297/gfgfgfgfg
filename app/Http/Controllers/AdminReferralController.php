<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionLevel;
use App\Models\ReferralQualification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminReferralController extends Controller
{
    public function settings(): View
    {
        $commissionSummary = $this->commissionSummary();

        return view('admin.referrals.settings', [
            'levels' => ReferralCommissionLevel::query()->whereIn('level', [1, 2, 3])->orderBy('level')->get(),
            'history' => AuditLog::query()
                ->whereIn('event', [
                    'admin.referral_commission_level_updated',
                    'admin.referral_commission_level_deactivated',
                    'admin.referral_commission_level_restored',
                ])
                ->with('actor')
                ->latest('id')
                ->limit(30)
                ->get(),
            'activeLevels' => ReferralCommissionLevel::query()->whereIn('level', [1, 2, 3])->where('is_active', true)->count(),
            'inactiveLevels' => ReferralCommissionLevel::query()->whereIn('level', [1, 2, 3])->where('is_active', false)->count(),
            'qualifiedReferrals' => ReferralQualification::query()->count(),
            ...$commissionSummary,
        ]);
    }

    public function updateLevel(Request $request, ReferralCommissionLevel $referralCommissionLevel): RedirectResponse
    {
        abort_unless(in_array((int) $referralCommissionLevel->level, [1, 2, 3], true), 404);
        $amountField = 'amount_'.$referralCommissionLevel->level;
        $validated = $request->validate([
            $amountField => ['required', 'string', 'regex:/^(?=.*[1-9])\d{1,18}(?:\.\d{1,2})?$/'],
        ]);
        $admin = $request->user('admin');

        DB::transaction(function () use ($request, $referralCommissionLevel, $validated, $admin, $amountField): void {
            $level = ReferralCommissionLevel::query()->whereKey($referralCommissionLevel->id)->lockForUpdate()->firstOrFail();
            $before = $this->levelSnapshot($level);
            $level->forceFill([
                'amount' => $validated[$amountField],
                'updated_by_user_id' => $admin->id,
            ])->save();

            $this->recordChange($request, $admin->id, 'admin.referral_commission_level_updated', $level, $before);
        });

        return back()->with('status', 'Referral commission amount updated. Previously recorded commissions are unchanged.');
    }

    public function updateLevelStatus(
        Request $request,
        ReferralCommissionLevel $referralCommissionLevel,
    ): RedirectResponse {
        abort_unless(in_array((int) $referralCommissionLevel->level, [1, 2, 3], true), 404);
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $admin = $request->user('admin');
        $isActive = (bool) $validated['is_active'];

        DB::transaction(function () use ($request, $referralCommissionLevel, $admin, $isActive): void {
            $level = ReferralCommissionLevel::query()->whereKey($referralCommissionLevel->id)->lockForUpdate()->firstOrFail();
            abort_if($level->is_active === $isActive, 422, 'The referral commission level already has that status.');
            $before = $this->levelSnapshot($level);
            $level->forceFill([
                'is_active' => $isActive,
                'updated_by_user_id' => $admin->id,
            ])->save();

            $event = $isActive
                ? 'admin.referral_commission_level_restored'
                : 'admin.referral_commission_level_deactivated';
            $this->recordChange($request, $admin->id, $event, $level, $before);
        });

        return back()->with('status', $isActive
            ? 'Referral commission level restored for future qualifications.'
            : 'Referral commission level deactivated for future qualifications.');
    }

    public function report(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'level' => ['nullable', Rule::in(['1', '2', '3'])],
            'status' => ['nullable', Rule::in(ReferralCommission::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null)
            && $filters['date_from'] > $filters['date_to']) {
            throw ValidationException::withMessages([
                'date_to' => 'The end date must be on or after the start date.',
            ]);
        }

        $commissions = ReferralCommission::query()
            ->with([
                'beneficiary:id,name,email,customer_id',
                'qualifiedUser:id,name,email,customer_id',
                'ledgerEntry:id,user_id,source_type,source_id,entry_type,amount,created_at',
            ])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    foreach (['beneficiary', 'qualifiedUser'] as $relation) {
                        $query->orWhereHas($relation, function ($users) use ($search): void {
                            $users->where('name', 'like', '%'.$search.'%')
                                ->orWhere('email', 'like', '%'.$search.'%')
                                ->orWhere('customer_id', 'like', '%'.$search.'%');
                        });
                    }
                    $query->orWhereHas('ledgerEntry', fn ($ledger) => $ledger->where('id', is_numeric($search) ? (int) $search : -1))
                        ->orWhereHas('triggeringWithdrawal', fn ($withdrawal) => $withdrawal->where('request_reference', 'like', '%'.$search.'%'));
                });
            })
            ->when($filters['level'] ?? null, fn ($query, string $level) => $query->where('level', (int) $level))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('qualified_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('qualified_at', '<=', $date))
            ->latest('qualified_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $commissionSummary = $this->commissionSummary();

        return view('admin.referrals.report', [
            'commissions' => $commissions,
            'filters' => $filters,
            ...$commissionSummary,
            'qualifiedReferrals' => ReferralQualification::query()->count(),
            'activeLevels' => ReferralCommissionLevel::query()->whereIn('level', [1, 2, 3])->where('is_active', true)->count(),
            'inactiveLevels' => ReferralCommissionLevel::query()->whereIn('level', [1, 2, 3])->where('is_active', false)->count(),
        ]);
    }

    private function commissionSummary(): array
    {
        $statusTotals = ReferralCommission::query()
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('COALESCE(ROUND(SUM(amount), 2), 0) as total_amount')
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 2), 0) as pending_amount', ['pending'])
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 2), 0) as credited_amount', ['credited'])
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 2), 0) as reversed_amount', ['reversed'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count', ['pending'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as credited_count', ['credited'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as reversed_count', ['reversed'])
            ->first();
        $levelTotals = ReferralCommission::query()
            ->select('level', DB::raw('SUM(amount) as total'))
            ->where('status', 'credited')
            ->groupBy('level')
            ->pluck('total', 'level');

        return [
            'commissionSummary' => $statusTotals,
            'levelTotals' => $levelTotals,
            'totalPaid' => (string) $statusTotals->credited_amount,
        ];
    }

    private function levelSnapshot(ReferralCommissionLevel $level): array
    {
        return [
            'level' => (int) $level->level,
            'amount' => (string) $level->amount,
            'is_active' => (bool) $level->is_active,
        ];
    }

    private function recordChange(
        Request $request,
        int $adminId,
        string $event,
        ReferralCommissionLevel $level,
        array $before,
    ): void {
        AuditLog::query()->create([
            'actor_user_id' => $adminId,
            'subject_user_id' => $adminId,
            'event' => $event,
            'auditable_type' => ReferralCommissionLevel::class,
            'auditable_id' => $level->id,
            'metadata' => [
                'before' => $before,
                'after' => $this->levelSnapshot($level),
                'previous_commissions_unchanged' => true,
            ],
            'ip_address' => $request->ip(),
        ]);
    }
}
