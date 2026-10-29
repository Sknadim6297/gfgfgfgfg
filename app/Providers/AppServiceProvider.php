<?php

namespace App\Providers;

use App\Auth\AdminUserProvider;
use App\Models\MemberNotice;
use App\Models\User;
use App\Support\MemberNoticePrompt;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('admins', function ($app, array $config): AdminUserProvider {
            return new AdminUserProvider($app['hash'], $config['model']);
        });

        if (app()->environment('production') && in_array(config('mail.default'), ['log', 'array'], true)) {
            throw new LogicException('Configure a real mail transport before running in production.');
        }

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $route = $user->role === 'admin' ? 'admin.password.reset' : 'password.reset';

            return route($route, [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        View::composer('layouts.app', function ($view): void {
            $request = request();
            $user = $request->user('web');
            $notices = collect();

            if (
                $user?->role === 'user'
                && ! $request->session()->has('admin_impersonation')
                && ! $request->routeIs('admin.*')
            ) {
                $dismissed = MemberNoticePrompt::dismissedIds($request);
                $notices = MemberNotice::query()
                    ->forPopup()
                    ->orderedForDisplay()
                    ->when($dismissed !== [], fn ($query) => $query->whereNotIn('id', $dismissed))
                    ->limit(20)
                    ->get();
            }

            $view->with('memberNoticePopups', $notices);
        });
    }
}
