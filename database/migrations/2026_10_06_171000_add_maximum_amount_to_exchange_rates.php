<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->decimal('maximum_amount', 20, 2)->nullable()->after('minimum_amount');
        });

        $plans = DB::table('exchange_rates')->orderBy('minimum_amount')->orderBy('id')->get();
        foreach ($plans as $index => $plan) {
            $next = $plans[$index + 1] ?? null;
            DB::table('exchange_rates')->where('id', $plan->id)->update([
                'maximum_amount' => $next ? $this->subtractCent((string) $next->minimum_amount) : null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropColumn('maximum_amount');
        });
    }

    private function subtractCent(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
        $cents = ltrim($whole.str_pad($fraction, 2, '0'), '0');
        $cents = $cents === '' ? '0' : $cents;

        for ($index = strlen($cents) - 1; $index >= 0; $index--) {
            if ($cents[$index] !== '0') {
                $cents[$index] = (string) ((int) $cents[$index] - 1);
                break;
            }
            $cents[$index] = '9';
        }

        $cents = str_pad(ltrim($cents, '0'), 3, '0', STR_PAD_LEFT);

        return substr($cents, 0, -2).'.'.substr($cents, -2);
    }
};
