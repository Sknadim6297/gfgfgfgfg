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
            $table->dropUnique(['pair']);
            $table->string('plan_key', 60)->nullable()->unique();
            $table->string('name', 120)->nullable()->unique();
            $table->string('label', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('icon', 80)->default('bi-currency-exchange');
            $table->decimal('minimum_amount', 20, 2)->default('0.00')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
        });

        $now = now();
        if (! DB::table('exchange_rates')->where('plan_key', 'base')->exists()) {
            $existingBase = DB::table('exchange_rates')->where('pair', 'USDT_INR')->first();
            $basePlan = [
                'pair' => 'USDT_INR',
                'plan_key' => 'base',
                'name' => 'Base Rate',
                'rate' => $existingBase?->rate ?? '100.00000000',
                'minimum_amount' => '0.00',
                'label' => 'LIVE RATE',
                'description' => 'Standard USDT to INR reference rate.',
                'icon' => 'bi-currency-exchange',
                'is_active' => true,
                'is_default' => true,
                'updated_at' => $now,
            ];

            if ($existingBase) {
                DB::table('exchange_rates')->where('id', $existingBase->id)->update($basePlan);
            } else {
                DB::table('exchange_rates')->insert([...$basePlan, 'created_at' => $now]);
            }
        }

        foreach ([
            [
                'plan_key' => 'prime',
                'name' => 'Prime Rate',
                'rate' => '115.00000000',
                'minimum_amount' => '10000.00',
                'label' => null,
                'description' => 'Preferred reference rate for qualifying deposits.',
                'icon' => 'bi-diamond-fill',
            ],
            [
                'plan_key' => 'vip',
                'name' => 'VIP Rate',
                'rate' => '120.00000000',
                'minimum_amount' => '20000.00',
                'label' => null,
                'description' => 'VIP reference rate for qualifying deposits.',
                'icon' => 'bi-gem',
            ],
        ] as $plan) {
            DB::table('exchange_rates')->insertOrIgnore([
                ...$plan,
                'pair' => 'USDT_INR',
                'is_active' => true,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('exchange_rates')->whereIn('plan_key', ['prime', 'vip'])->orWhere('is_default', false)->delete();

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropUnique(['plan_key']);
            $table->dropUnique(['name']);
            $table->dropUnique(['minimum_amount']);
            $table->dropIndex(['is_active']);
            $table->dropIndex(['is_default']);
            $table->dropColumn([
                'plan_key',
                'name',
                'label',
                'description',
                'icon',
                'minimum_amount',
                'is_active',
                'is_default',
            ]);
            $table->unique('pair');
        });
    }
};
