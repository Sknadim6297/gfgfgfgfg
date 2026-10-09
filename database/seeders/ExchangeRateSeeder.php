<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use Illuminate\Database\Seeder;

class ExchangeRateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            [
                'plan_key' => 'base',
                'name' => 'Base Rate',
                'rate' => '100.00000000',
                'minimum_amount' => '0.00',
                'maximum_amount' => '9999.99999999',
                'label' => 'LIVE RATE',
                'description' => 'Applicable for sell amounts below 10,000 USDT.',
                'icon' => 'bi-currency-exchange',
            ],
            [
                'plan_key' => 'prime',
                'name' => 'Prime Rate',
                'rate' => '115.00000000',
                'minimum_amount' => '10000.00',
                'maximum_amount' => '19999.99999999',
                'label' => null,
                'description' => 'Applicable for sell amounts from 10,000 to 19,999.99999999 USDT.',
                'icon' => 'bi-diamond-fill',
            ],
            [
                'plan_key' => 'vip',
                'name' => 'VIP Rate',
                'rate' => '120.00000000',
                'minimum_amount' => '20000.00',
                'maximum_amount' => null,
                'label' => null,
                'description' => 'Applicable for sell amounts of 20,000 USDT or more.',
                'icon' => 'bi-gem',
            ],
        ] as $plan) {
            ExchangeRate::firstOrCreate(
                ['plan_key' => $plan['plan_key']],
                [
                    ...$plan,
                    'pair' => 'USDT_INR',
                    'is_active' => true,
                    'is_default' => true,
                ],
            );
        }
    }
}
