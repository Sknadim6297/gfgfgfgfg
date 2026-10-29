<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
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

        return redirect()->route('admin.dashboard');
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
