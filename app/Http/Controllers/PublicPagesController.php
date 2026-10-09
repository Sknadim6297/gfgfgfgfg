<?php

namespace App\Http\Controllers;

use App\Models\ExchangeRate;
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
        ]);
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
