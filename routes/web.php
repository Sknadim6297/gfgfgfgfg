<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminDepositController;
use App\Http\Controllers\AdminDepositSettingsController;
use App\Http\Controllers\AdminExchangeController;
use App\Http\Controllers\AdminMemberNoticeController;
use App\Http\Controllers\AdminPushNotificationController;
use App\Http\Controllers\AdminRateController;
use App\Http\Controllers\AdminReferralController;
use App\Http\Controllers\AdminSupportTicketController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminWithdrawalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerExchangeRequestController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\FcmDeviceTokenController;
use App\Http\Controllers\MemberNoticeController;
use App\Http\Controllers\PublicPagesController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WalletTransactionPinController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicPagesController::class, 'landing'])->name('landing');
Route::get('/exchange', [PublicPagesController::class, 'exchange'])->name('exchange');
Route::get('/exchange/sell/estimate', [CustomerExchangeRequestController::class, 'estimate'])->middleware('route.throttle:60,1')->name('exchange.sell.estimate');
Route::get('/contact', [SupportTicketController::class, 'index'])->name('contact');
Route::post('/support/tickets', [SupportTicketController::class, 'store'])
    ->middleware(['route.throttle:5,1', 'impersonation.readonly'])
    ->name('support.tickets.store');
Route::get('/dashboard', fn () => redirect()->route('home'))->name('dashboard');

Route::middleware('guest:web')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('route.throttle:6,1')->name('login.store');
    Route::get('/register', fn () => view('pages.register'))->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('route.throttle:5,1')->name('register.store');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('route.throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('route.throttle:5,1')->name('password.update');
});

Route::middleware(['auth:web', 'auth.session', 'impersonation.readonly', 'member.notice-session'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('active.account')->group(function (): void {
        Route::get('/support/tickets/{ticket:ticket_number}', [SupportTicketController::class, 'show'])->name('support.tickets.show');
        Route::post('/support/tickets/{ticket:ticket_number}/messages', [SupportTicketController::class, 'reply'])->middleware('route.throttle:10,1')->name('support.tickets.reply');
        Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
        Route::get('/notices', [MemberNoticeController::class, 'index'])->name('notices.index');
        Route::get('/notices/{memberNotice}', [MemberNoticeController::class, 'show'])->name('notices.show');
        Route::post('/notices/{memberNotice}/dismiss', [MemberNoticeController::class, 'dismiss'])->middleware('route.throttle:30,1')->name('notices.dismiss');
        Route::post('/profile/notifications/device-tokens', [FcmDeviceTokenController::class, 'store'])
            ->middleware('route.throttle:10,1')
            ->name('profile.notifications.device-tokens.store');
        Route::delete('/profile/notifications/device-tokens', [FcmDeviceTokenController::class, 'destroy'])
            ->middleware('route.throttle:10,1')
            ->name('profile.notifications.device-tokens.destroy');
        Route::get('/profile/bank', [AuthController::class, 'bankDetails'])->name('profile.bank');
        Route::get('/profile/exchanges', [AuthController::class, 'exchangeHistory'])->name('profile.exchanges');
        Route::get('/profile/exchanges/{exchangeRequest:request_reference}', [CustomerExchangeRequestController::class, 'show'])->name('profile.exchanges.show');
        Route::get('/profile/referrals', [AuthController::class, 'referrals'])->name('profile.referrals');
        Route::get('/profile/referrals/history', [AuthController::class, 'referralHistory'])->name('profile.referrals.history');
        Route::get('/profile/referrals/{referral}', [AuthController::class, 'referralMember'])->whereNumber('referral')->name('profile.referrals.member');
        Route::get('/profile/password', [AuthController::class, 'showChangePassword'])->name('profile.password');
        Route::get('/profile/wallet-transaction-pin', [WalletTransactionPinController::class, 'show'])->name('profile.wallet-pin');
        Route::post('/wallet-transaction-pin/otp', [WalletTransactionPinController::class, 'sendOtp'])->name('wallet-pin.otp.send');
        Route::post('/wallet-transaction-pin/otp/verify', [WalletTransactionPinController::class, 'verifyOtp'])->middleware('route.throttle:10,10')->name('wallet-pin.otp.verify');
        Route::post('/wallet-transaction-pin', [WalletTransactionPinController::class, 'save'])->middleware('route.throttle:5,10')->name('wallet-pin.save');
        Route::put('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
        Route::put('/security/password', [AuthController::class, 'changePassword'])->middleware('route.throttle:6,1')->name('password.change');
        Route::get('/home', [PublicPagesController::class, 'home'])->name('home');
        Route::get('/wallet', WalletController::class)->name('wallet');
        Route::get('/exchange/sell', [CustomerExchangeRequestController::class, 'create'])->name('exchange.sell');
        Route::post('/exchange/requests/quote', [CustomerExchangeRequestController::class, 'quote'])->middleware('route.throttle:10,1')->name('exchange.requests.quote');
        Route::post('/exchange/requests', [CustomerExchangeRequestController::class, 'store'])->middleware('financial.throttle:sell,5,1')->name('exchange.requests.store');
        Route::get('/exchange/{exchangeRequest:request_reference}', [CustomerExchangeRequestController::class, 'show'])->name('exchange.requests.show');
        Route::get('/wallet/withdrawals/create', [WithdrawalController::class, 'create'])->name('wallet.withdrawals.create');
        Route::post('/wallet/withdrawals', [WithdrawalController::class, 'store'])->middleware('financial.throttle:withdrawal,5,10')->name('wallet.withdrawals.store');
        Route::get('/wallet/withdrawals/{withdrawalRequest}', [WithdrawalController::class, 'show'])->name('wallet.withdrawals.show');
        Route::get('/wallet/withdrawals/{withdrawalRequest}/invoice', [WithdrawalController::class, 'invoice'])->name('wallet.withdrawals.invoice');
        Route::get('/deposit', [DepositController::class, 'create'])->name('deposit.create');
        Route::post('/deposit', [DepositController::class, 'store'])->middleware('financial.throttle:deposit,5,1')->name('deposit.store');
        Route::get('/deposit/payment-qr', [DepositController::class, 'paymentQr'])->name('deposit.payment-qr');
        Route::get('/deposit/network-logo', [DepositController::class, 'networkLogo'])->name('deposit.network-logo');
        Route::get('/deposits/{deposit}', [DepositController::class, 'show'])->name('deposits.show');
        Route::post('/deposits/{deposit}/transaction-hash', [DepositController::class, 'submitTransactionHash'])->middleware('financial.throttle:deposit,5,1')->name('deposits.transaction-hash.store');
        Route::get('/deposits/{deposit}/images/{image}', [DepositController::class, 'image'])->whereIn('image', ['logo', 'qr'])->name('deposits.image');
        Route::get('/deposits/{deposit}/proof', [DepositController::class, 'proof'])->name('deposits.proof');
    });
});

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest:admin')->group(function (): void {
        Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AdminAuthController::class, 'login'])->middleware('route.throttle:6,1')->name('login.store');
        Route::get('/forgot-password', [AdminAuthController::class, 'showForgotPassword'])->name('password.request');
        Route::post('/forgot-password', [AdminAuthController::class, 'sendResetLink'])->middleware('route.throttle:5,1')->name('password.email');
        Route::get('/reset-password/{token}', [AdminAuthController::class, 'showResetPassword'])->name('password.reset');
        Route::post('/reset-password', [AdminAuthController::class, 'resetPassword'])->middleware('route.throttle:5,1')->name('password.update');
    });

    Route::middleware(['auth:admin', 'admin.session', 'admin.role'])->group(function (): void {
        Route::get('/two-factor/setup', [AdminAuthController::class, 'showSetup'])->name('2fa.setup');
        Route::post('/two-factor/setup', [AdminAuthController::class, 'enableTwoFactor'])->middleware('route.throttle:6,1')->name('2fa.enable');
        Route::get('/two-factor/challenge', [AdminAuthController::class, 'showChallenge'])->name('2fa.challenge');
        Route::post('/two-factor/challenge', [AdminAuthController::class, 'verifyTwoFactor'])->middleware('route.throttle:6,1')->name('2fa.verify');

        Route::middleware('admin.2fa')->group(function (): void {
            Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
            Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
            Route::get('/', AdminDashboardController::class);
            Route::get('/profile', [AdminAuthController::class, 'profile'])->name('profile');
            Route::put('/profile', [AdminAuthController::class, 'updateProfile'])->name('profile.update');
            Route::put('/security/password', [AdminAuthController::class, 'changePassword'])->name('password.change');

            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [AdminUserController::class, 'show'])->middleware('bank.verification')->name('users.show');
            Route::post('/users/{user}/impersonate', [AdminUserController::class, 'impersonate'])->name('users.impersonate');
            Route::post('/impersonation/stop', [AdminUserController::class, 'stopImpersonating'])->name('impersonation.stop');
            Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/bank-verification/verify', [AdminUserController::class, 'verifyBank'])->middleware('bank.verification')->name('users.bank.verify');
            Route::post('/users/{user}/bank-verification/reject', [AdminUserController::class, 'rejectBank'])->middleware('bank.verification')->name('users.bank.reject');

            Route::get('/exchanges', [AdminExchangeController::class, 'index'])->name('exchanges.index');
            Route::post('/exchanges/{exchangeRequest:request_reference}/reject', [AdminExchangeController::class, 'reject'])->name('exchanges.reject');
            Route::get('/exchanges/{exchangeRequest:request_reference}', [AdminExchangeController::class, 'show'])->name('exchanges.show');
            Route::put('/exchanges/{exchangeRequest:request_reference}', [AdminExchangeController::class, 'update'])->name('exchanges.update');
            Route::get('/withdrawals', [AdminWithdrawalController::class, 'index'])->name('withdrawals.index');
            Route::get('/withdrawals/{withdrawalRequest}', [AdminWithdrawalController::class, 'show'])->name('withdrawals.show');
            Route::put('/withdrawals/{withdrawalRequest}', [AdminWithdrawalController::class, 'update'])->name('withdrawals.update');
            Route::get('/support-tickets', [AdminSupportTicketController::class, 'index'])->name('support-tickets.index');
            Route::get('/support-tickets/{ticket:ticket_number}', [AdminSupportTicketController::class, 'show'])->name('support-tickets.show');
            Route::post('/support-tickets/{ticket:ticket_number}/messages', [AdminSupportTicketController::class, 'reply'])->middleware('route.throttle:20,1')->name('support-tickets.reply');
            Route::put('/support-tickets/{ticket:ticket_number}', [AdminSupportTicketController::class, 'update'])->name('support-tickets.update');
            Route::get('/push-notifications', [AdminPushNotificationController::class, 'index'])->name('push-notifications.index');
            Route::get('/notices', [AdminMemberNoticeController::class, 'index'])->name('notices.index');
            Route::get('/notices/create', [AdminMemberNoticeController::class, 'create'])->name('notices.create');
            Route::post('/notices', [AdminMemberNoticeController::class, 'store'])->name('notices.store');
            Route::get('/notices/{memberNotice}/edit', [AdminMemberNoticeController::class, 'edit'])->name('notices.edit');
            Route::put('/notices/{memberNotice}', [AdminMemberNoticeController::class, 'update'])->name('notices.update');
            Route::patch('/notices/{memberNotice}/status', [AdminMemberNoticeController::class, 'updateStatus'])->name('notices.status');
            Route::delete('/notices/{memberNotice}', [AdminMemberNoticeController::class, 'destroy'])->name('notices.destroy');
            Route::post('/push-notifications', [AdminPushNotificationController::class, 'store'])
                ->middleware('route.throttle:10,1')
                ->name('push-notifications.store');

            Route::get('/referrals/settings', [AdminReferralController::class, 'settings'])->name('referrals.settings');
            Route::put('/referrals/levels/{referralCommissionLevel}', [AdminReferralController::class, 'updateLevel'])->name('referrals.levels.update');
            Route::patch('/referrals/levels/{referralCommissionLevel}/status', [AdminReferralController::class, 'updateLevelStatus'])->name('referrals.levels.status');
            Route::get('/referrals/commissions', [AdminReferralController::class, 'report'])->name('referrals.commissions');

            Route::get('/rates', [AdminRateController::class, 'show'])->name('rates.edit');
            Route::post('/rates', [AdminRateController::class, 'store'])->name('rates.store');
            Route::put('/rates', [AdminRateController::class, 'update'])->name('rates.update');
            Route::put('/rates/{exchangeRate}', [AdminRateController::class, 'updatePlan'])->name('rates.plans.update');
            Route::patch('/rates/{exchangeRate}/status', [AdminRateController::class, 'toggleStatus'])->name('rates.plans.status');
            Route::delete('/rates/{exchangeRate}', [AdminRateController::class, 'destroy'])->name('rates.plans.destroy');

            Route::get('/deposit-settings', [AdminDepositSettingsController::class, 'edit'])->name('deposit-settings.edit');
            Route::put('/deposit-settings', [AdminDepositSettingsController::class, 'update'])->name('deposit-settings.update');
            Route::delete('/deposit-settings', [AdminDepositSettingsController::class, 'destroy'])->name('deposit-settings.destroy');
            Route::get('/deposit-settings/qr', [AdminDepositSettingsController::class, 'qr'])->name('deposit-settings.qr');
            Route::get('/deposit-settings/logo', [AdminDepositSettingsController::class, 'logo'])->name('deposit-settings.logo');
            Route::get('/deposits', [AdminDepositController::class, 'index'])->name('deposits.index');
            Route::get('/deposits/{deposit}', [AdminDepositController::class, 'show'])->name('deposits.show');
            Route::put('/deposits/{deposit}', [AdminDepositController::class, 'update'])->name('deposits.update');
            Route::get('/deposits/{deposit}/images/{image}', [AdminDepositController::class, 'image'])->whereIn('image', ['logo', 'qr'])->name('deposits.image');
            Route::get('/deposits/{deposit}/proof', [AdminDepositController::class, 'proof'])->name('deposits.proof');
        });
    });
});
