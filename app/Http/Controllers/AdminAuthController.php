<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('admin.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $key = 'admin-login:'.Str::lower($validated['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $admin = User::where('email', Str::lower($validated['email']))
            ->where('role', 'admin')
            ->where('account_status', 'active')
            ->first();

        if (! $admin || ! Hash::check($validated['password'], $admin->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($key);
        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();
        $request->session()->forget('admin_2fa_verified');

        return redirect()->route($admin->totp_enabled ? 'admin.2fa.challenge' : 'admin.2fa.setup');
    }

    public function showForgotPassword(): View
    {
        return view('admin.auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $email = Str::lower($request->string('email')->toString());
        $adminExists = User::where('email', $email)->where('role', 'admin')->exists();

        if ($adminExists) {
            Password::sendResetLink(['email' => $email]);
        }

        return back()->with('status', 'If an admin account matches that address, a reset link will be sent.');
    }

    public function showResetPassword(Request $request, string $token): View
    {
        return view('admin.auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        $adminExists = User::where('email', Str::lower($validated['email']))->where('role', 'admin')->exists();
        $status = $adminExists
            ? Password::reset($validated, function (User $user, string $password) use ($request): void {
                abort_unless($user->role === 'admin', 403);
                DB::transaction(function () use ($user, $password, $request): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    AuditLog::create([
                        'subject_user_id' => $user->id,
                        'event' => 'admin.password_reset',
                        'metadata' => [],
                        'ip_address' => $request->ip(),
                    ]);
                });
            })
            : Password::INVALID_TOKEN;

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('admin.login')->with('status', 'Password reset. You can now sign in.')
            : back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }

    public function showSetup(Request $request): View|RedirectResponse
    {
        $admin = $request->user('admin');

        if ($admin->totp_enabled) {
            return redirect()->route('admin.2fa.challenge');
        }

        if (! $admin->totp_secret) {
            $admin->forceFill(['totp_secret' => Totp::generateSecret()])->save();
        }

        return view('admin.auth.two-factor-setup', [
            'secret' => $admin->totp_secret,
            'provisioningUri' => Totp::provisioningUri($admin->totp_secret, $admin->email),
            'demoCode' => $this->demoCodeForDisplay($request),
        ]);
    }

    public function enableTwoFactor(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $admin = $request->user('admin');
        $usingDemoCode = $this->demoCodeMatches($request, $validated['code']);

        $enabledAdmin = DB::transaction(function () use ($admin, $request, $validated, $usingDemoCode): User {
            $lockedAdmin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $counter = $usingDemoCode ? null : ($lockedAdmin->totp_secret
                ? Totp::matchingCounter($lockedAdmin->totp_secret, $validated['code'])
                : null);

            if ($lockedAdmin->totp_enabled || (! $usingDemoCode && ($counter === null || ($lockedAdmin->totp_last_counter !== null && $counter <= $lockedAdmin->totp_last_counter)))) {
                throw ValidationException::withMessages(['code' => 'The authenticator code is invalid or already used.']);
            }

            $attributes = ['totp_enabled' => true];

            if ($counter !== null) {
                $attributes['totp_last_counter'] = $counter;
            }

            $lockedAdmin->forceFill($attributes)->save();
            AuditLog::create([
                'actor_user_id' => $lockedAdmin->id,
                'subject_user_id' => $lockedAdmin->id,
                'event' => 'admin.two_factor_enabled',
                'metadata' => [],
                'ip_address' => $request->ip(),
            ]);

            return $lockedAdmin;
        });
        $this->forgetDemoCode($request);
        Auth::guard('admin')->setUser($enabledAdmin);
        $request->session()->put('admin_2fa_verified', true);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', 'Two-factor authentication is enabled.');
    }

    public function showChallenge(Request $request): View|RedirectResponse
    {
        if (! Auth::guard('admin')->user()->totp_enabled) {
            return redirect()->route('admin.2fa.setup');
        }

        return view('admin.auth.two-factor-challenge', [
            'demoCode' => $this->demoCodeForDisplay($request),
        ]);
    }

    public function verifyTwoFactor(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $admin = $request->user('admin');
        $usingDemoCode = $this->demoCodeMatches($request, $validated['code']);

        DB::transaction(function () use ($admin, $validated, $usingDemoCode): void {
            $lockedAdmin = User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $counter = $usingDemoCode ? null : ($lockedAdmin->totp_enabled && $lockedAdmin->totp_secret
                ? Totp::matchingCounter($lockedAdmin->totp_secret, $validated['code'])
                : null);

            if (! $usingDemoCode && ($counter === null || ($lockedAdmin->totp_last_counter !== null && $counter <= $lockedAdmin->totp_last_counter))) {
                throw ValidationException::withMessages(['code' => 'The authenticator code is invalid or already used.']);
            }

            if ($counter !== null) {
                $lockedAdmin->forceFill(['totp_last_counter' => $counter])->save();
            }
        });
        $this->forgetDemoCode($request);
        $request->session()->put('admin_2fa_verified', true);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', 'Signed in securely.');
    }

    private function demoTwoFactorEnabled(): bool
    {
        return (bool) config('admin.2fa_demo')
            && config('app.env') === 'local'
            && (bool) config('app.debug');
    }

    private function demoCodeForDisplay(Request $request): ?string
    {
        if (! $this->demoTwoFactorEnabled()) {
            $this->forgetDemoCode($request);

            return null;
        }

        if ((int) $request->session()->get('admin_2fa_demo_expires_at', 0) <= now()->timestamp) {
            $request->session()->put('admin_2fa_demo_code', str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT));
            $request->session()->put('admin_2fa_demo_expires_at', now()->addMinutes(5)->timestamp);
        }

        return $request->session()->get('admin_2fa_demo_code');
    }

    private function demoCodeMatches(Request $request, string $code): bool
    {
        if (! $this->demoTwoFactorEnabled()) {
            $this->forgetDemoCode($request);

            return false;
        }

        $demoCode = $request->session()->get('admin_2fa_demo_code');
        $expiresAt = (int) $request->session()->get('admin_2fa_demo_expires_at', 0);

        return is_string($demoCode)
            && $expiresAt > now()->timestamp
            && hash_equals($demoCode, $code);
    }

    private function forgetDemoCode(Request $request): void
    {
        $request->session()->forget(['admin_2fa_demo_code', 'admin_2fa_demo_expires_at']);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function profile(Request $request): View
    {
        return view('admin.profile', ['admin' => $request->user('admin')]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $admin = $request->user('admin');

        DB::transaction(function () use ($admin, $validated, $request): void {
            $admin->update($validated);
            AuditLog::create([
                'actor_user_id' => $admin->id,
                'subject_user_id' => $admin->id,
                'event' => 'admin.profile_updated',
                'metadata' => [],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with('status', 'Admin profile updated.');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password:admin'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);
        $admin = $request->user('admin');

        DB::transaction(function () use ($admin, $validated, $request): void {
            $admin->forceFill([
                'password' => $validated['password'],
                'remember_token' => Str::random(60),
            ])->save();
            AuditLog::create([
                'actor_user_id' => $admin->id,
                'subject_user_id' => $admin->id,
                'event' => 'admin.password_changed',
                'metadata' => [],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with('status', 'Password changed.');
    }
}
