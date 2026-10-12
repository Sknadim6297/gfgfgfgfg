<?php

namespace App\Http\Controllers;

use App\Models\DepositSettings;
use App\Models\ExchangeRate;
use App\Support\DemoPlatformActivity;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PublicPagesController extends Controller
{
    public function home(): View
    {
        $customer = auth('web')->user();
        $ratePlans = $this->activeRatePlans();

        return view('pages.index', [
            'baseRate' => $ratePlans->firstWhere('plan_key', 'base'),
            'recordedBalance' => $customer?->role === 'user' ? $customer->balance : null,
            'demoActivity' => DemoPlatformActivity::forDate(),
        ]);
    }

    public function landing(): View
    {
        return view('pages.landing');
    }

    public function exchange(): View
    {
        $customer = auth('web')->user();
        $ratePlans = $this->activeRatePlans();

        return view('pages.exchange', [
            'ratePlans' => $ratePlans,
            'recordedBalance' => $customer?->role === 'user' ? $customer->balance : null,
            'depositAvailable' => $this->depositAvailable(),
        ]);
    }

    private function depositAvailable(): bool
    {
        $settings = DepositSettings::query()->find(1);
        $qrPath = $settings?->qr_path;
        $qrImageAvailable = DepositSettings::isManagedQrPath($qrPath)
            && Storage::disk('local')->exists($qrPath);

        return $settings?->hasActivePaymentSettings($qrImageAvailable) ?? false;
    }

    private function activeRatePlans()
    {
        return ExchangeRate::query()
            ->where('is_active', true)
            ->orderBy('minimum_amount')
            ->orderBy('id')
            ->get();
    }
}
