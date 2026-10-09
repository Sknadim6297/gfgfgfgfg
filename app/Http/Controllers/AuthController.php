<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExchangeRequest;
use App\Models\ReferralCommission;
use App\Models\ReferralCommissionLevel;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\ReferralCommissionService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('pages.login');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge([
            'referral_code' => Str::upper(trim((string) $request->input('referral_code', ''))) ?: null,
        ]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', 'in:Male,Female,Other'],
            'referral_code' => [
                'nullable',
                'string',
                'size:12',
                'regex:/^[A-Z0-9]+$/',
                Rule::exists('users', 'referral_code')->where(fn ($query) => $query->where('role', 'user')),
            ],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'terms' => ['accepted'],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $referrer = isset($validated['referral_code'])
                ? User::query()->where('role', 'user')->where('referral_code', $validated['referral_code'])->firstOrFail()
                : null;
            $customer = new User([
                'name' => $validated['name'],
                'email' => Str::lower($validated['email']),
                'mobile' => $validated['mobile'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'password' => $validated['password'],
            ]);
            $customer->setAttribute('referred_by_user_id', $referrer?->id);
            $customer->save();

            return $customer;
        });

        Auth::guard('web')->login($user);
        $request->session()->forget('url.intended');
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'Your account has been created successfully.');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $key = 'user-login:'.Str::lower($validated['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $user = User::where('email', Str::lower($validated['email']))->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if ($user->role !== 'user' || $user->account_status !== 'active') {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => 'Unable to sign in with these credentials.']);
        }

        RateLimiter::clear($key);
        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->forget('url.intended');
        $request->session()->regenerate();

        return redirect()->intended(route('home'))->with('status', 'Signed in successfully.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing')->with('status', 'Signed out successfully.');
    }

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => Str::lower($request->string('email')->toString())]);

        return back()->with('status', 'If an account matches that address, a password reset link has been sent.');
    }

    public function showResetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        if (User::where('email', Str::lower($validated['email']))->where('role', 'admin')->exists()) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'This reset link is not valid for a user account.',
            ]);
        }

        $status = Password::reset($validated, function (User $user, string $password): void {
            abort_unless($user->role === 'user', 403);

            DB::transaction(function () use ($user, $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                AuditLog::create([
                    'subject_user_id' => $user->id,
                    'event' => 'user.password_reset',
                    'metadata' => [],
                ]);
            });
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }

    public function profile(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        $bankVerificationStatus = $user->bankVerificationStatus();

        return view('pages.profile', compact('user', 'bankVerificationStatus'));
    }

    public function bankDetails(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);
        $bankVerificationStatus = $user->bankVerificationStatus();

        return view('pages.profile-bank', compact('user', 'bankVerificationStatus'));
    }

    public function exchangeHistory(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(ExchangeRequest::STATUSES)],
        ]);
        $hasFilters = filled($filters['search'] ?? null) || filled($filters['status'] ?? null);
        $exchanges = $user->exchangeRequests()
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('request_reference', 'like', '%'.$search.'%')
                        ->orWhere('transaction_reference', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('pages.profile-exchanges', compact('user', 'exchanges', 'hasFilters'));
    }

    public function referrals(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        $levels = ReferralCommissionLevel::query()
            ->whereIn('level', [1, 2, 3])
            ->orderBy('level')
            ->get(['level', 'amount', 'is_active']);
        $creditedLevels = $user->referralCommissions()
            ->where('status', 'credited')
            ->distinct()
            ->pluck('level')
            ->map(fn ($level): int => (int) $level)
            ->all();
        $totalEarned = '0.00';

        $user->referralCommissions()
            ->where('status', 'credited')
            ->orderBy('id')
            ->select(['id', 'amount'])
            ->chunkById(500, function (Collection $commissions) use (&$totalEarned): void {
                $commissions->each(function (ReferralCommission $commission) use (&$totalEarned): void {
                    $amount = (string) $commission->amount;
                    $totalEarned = Decimal::add($totalEarned, $amount, 2);
                });
            });

        return view('pages.profile-referrals', compact('user', 'levels', 'creditedLevels', 'totalEarned'));
    }

    public function referralHistory(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        $referrals = $user->referrals()
            ->where('role', 'user')
            ->with('referralQualification')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['id', 'customer_id', 'referred_by_user_id', 'created_at'], 'referral_page')
            ->withQueryString();
        $referralProgress = $this->referralProgress($referrals->getCollection());
        $commissions = $user->referralCommissions()
            ->with(['qualifiedUser:id,customer_id'])
            ->latest('qualified_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'commission_page')
            ->withQueryString();
        $estimatedLevelOneReward = ReferralCommissionLevel::query()
            ->where('level', 1)
            ->where('is_active', true)
            ->value('amount');

        return view('pages.profile-referral-history', compact(
            'user',
            'referrals',
            'referralProgress',
            'commissions',
            'estimatedLevelOneReward',
        ));
    }

    /**
     * @param  Collection<int, User>  $referrals
     * @return array<int, array{eligible_total: string, remaining: string, qualified: bool}>
     */
    private function referralProgress(Collection $referrals): array
    {
        if ($referrals->isEmpty()) {
            return [];
        }

        $totals = $referrals->mapWithKeys(
            fn (User $referral): array => [(int) $referral->id => '0.00000000']
        )->all();

        WithdrawalRequest::query()
            ->whereIn('user_id', array_keys($totals))
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereNotNull('usdt_inr_rate_snapshot')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get(['user_id', 'amount', 'usdt_inr_rate_snapshot'])
            ->each(function (WithdrawalRequest $withdrawal) use (&$totals): void {
                $rate = (string) $withdrawal->usdt_inr_rate_snapshot;
                if (Decimal::compare($rate, '0.00000000', 8) <= 0) {
                    return;
                }

                $equivalent = Decimal::divideToScale(
                    (string) $withdrawal->amount,
                    $rate,
                    dividendScale: 2,
                    divisorScale: 8,
                    resultScale: 8,
                );
                $userId = (int) $withdrawal->user_id;
                $totals[$userId] = Decimal::add($totals[$userId], $equivalent, 8);
            });

        $threshold = ReferralCommissionService::QUALIFICATION_THRESHOLD_USDT;

        return $referrals->mapWithKeys(function (User $referral) use ($totals, $threshold): array {
            $total = $totals[(int) $referral->id] ?? '0.00000000';
            $remaining = Decimal::compare($total, $threshold, 8) >= 0
                ? '0.00000000'
                : Decimal::subtract($threshold, $total, 8);

            return [(int) $referral->id => [
                'eligible_total' => $total,
                'remaining' => $remaining,
                'qualified' => $referral->referralQualification !== null,
            ]];
        })->all();
    }

    public function showChangePassword(Request $request): View
    {
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        return view('pages.profile-password', compact('user'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $section = $request->validate([
            'section' => ['required', Rule::in(['personal', 'bank', 'wallet'])],
        ])['section'];
        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);

        if ($section === 'personal') {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'mobile' => ['nullable', 'string', 'max:20'],
                'gender' => ['nullable', Rule::in(['Male', 'Female', 'Other'])],
            ]);
            $event = 'user.profile_updated';
            $message = 'Profile details updated.';
        } elseif ($section === 'bank') {
            $validator = Validator::make($request->all(), [
                'account_holder_name' => ['required', 'string', 'max:120'],
                'bank_name' => ['required', 'string', 'max:120'],
                'account_number' => ['required', 'string', 'regex:/^\d{6,20}$/'],
                'ifsc_code' => ['required', 'string', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
                'branch_name' => ['required', 'string', 'max:120'],
                'account_type' => ['required', Rule::in(['Savings', 'Current'])],
            ]);
            if ($validator->fails()) {
                return redirect()->route('profile.bank')
                    ->withErrors($validator)
                    ->withInput($request->except([
                        'account_number',
                        'bank_verification_status',
                        'bank_verification_reason',
                        'bank_submitted_at',
                        'bank_verified_at',
                        'bank_reviewed_by_user_id',
                        'user_id',
                        'usdt_wallet_address',
                        'wallet_current_password',
                    ]));
            }
            $validated = $validator->validated();
            $validated['ifsc_code'] = strtoupper($validated['ifsc_code']);

            $message = DB::transaction(function () use ($request, $validated, $user): string {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $wasComplete = $lockedUser->hasCompleteBankDetails();
                $fields = [
                    'account_holder_name',
                    'bank_name',
                    'account_number',
                    'ifsc_code',
                    'branch_name',
                    'account_type',
                ];
                $changed = collect($fields)->contains(
                    fn (string $field): bool => (string) $lockedUser->{$field} !== (string) $validated[$field]
                );
                $wasRejected = $lockedUser->bankVerificationStatus() === 'rejected';
                $submittedForVerification = ! $wasComplete || $changed || $wasRejected;

                $lockedUser->forceFill($validated);
                if ($submittedForVerification) {
                    $lockedUser->forceFill([
                        'bank_verification_status' => 'pending',
                        'bank_verification_reason' => null,
                        'bank_submitted_at' => now(),
                        'bank_verified_at' => null,
                        'bank_reviewed_by_user_id' => null,
                    ]);
                }
                $lockedUser->save();

                AuditLog::create([
                    'actor_user_id' => $lockedUser->id,
                    'subject_user_id' => $lockedUser->id,
                    'event' => $wasComplete ? 'user.bank_details_updated' : 'user.bank_details_submitted',
                    'metadata' => [
                        'updated_fields' => array_keys($validated),
                        'verification_status' => $lockedUser->bankVerificationStatus(),
                    ],
                    'ip_address' => $request->ip(),
                ]);

                if (! $wasComplete) {
                    return 'Bank details submitted for verification.';
                }

                if ($submittedForVerification) {
                    return 'Your bank details were updated and have been sent for verification again.';
                }

                return 'Bank details updated.';
            });

            return redirect()->route('profile.bank')->with('status', $message);
        } else {
            $validator = Validator::make($request->all(), [
                'wallet_current_password' => ['required', 'current_password:web'],
                'usdt_wallet_address' => ['required', 'string', 'max:255'],
            ]);
            if ($validator->fails()) {
                return redirect()->route('profile.bank')
                    ->withErrors($validator)
                    ->withInput($request->except([
                        'account_holder_name',
                        'bank_name',
                        'account_number',
                        'ifsc_code',
                        'branch_name',
                        'account_type',
                        'usdt_wallet_address',
                        'wallet_current_password',
                    ]));
            }
            $validated = $validator->validated();
            unset($validated['wallet_current_password']);
            $event = 'user.wallet_details_updated';
            $message = 'USDT wallet details updated.';
        }

        DB::transaction(function () use ($request, $validated, $user, $event): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedUser->fill($validated)->save();
            AuditLog::create([
                'actor_user_id' => $lockedUser->id,
                'subject_user_id' => $lockedUser->id,
                'event' => $event,
                'metadata' => ['updated_fields' => array_keys($validated)],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route(in_array($section, ['bank', 'wallet'], true) ? 'profile.bank' : 'profile')->with('status', $message);
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        $user = $request->user('web');
        abort_unless($user?->role === 'user', 403);
        DB::transaction(function () use ($user, $validated, $request): void {
            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'remember_token' => Str::random(60),
            ])->save();
            AuditLog::create([
                'actor_user_id' => $user->id,
                'subject_user_id' => $user->id,
                'event' => 'user.password_changed',
                'metadata' => [],
                'ip_address' => $request->ip(),
            ]);
        });

        Auth::guard('web')->logoutOtherDevices($validated['password']);
        $request->session()->regenerate();

        return redirect()->route('profile.password')->with('status', 'Password changed.');
    }
}
