<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('balance', 20, 8)->default('0.00000000')->change();
        });

        Schema::table('balance_ledger_entries', function (Blueprint $table): void {
            $table->decimal('amount', 20, 8)->change();
        });

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->decimal('minimum_amount', 20, 8)->default('0.00000000')->change();
            $table->decimal('maximum_amount', 20, 8)->nullable()->change();
        });

        Schema::table('exchange_requests', function (Blueprint $table): void {
            $table->string('rate_plan_key', 60)->nullable()->after('rate_plan_name');
            $table->decimal('available_usd_before', 20, 8)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->index(['rate_plan_key', 'created_at'], 'exchange_rate_plan_created_index');
        });

        foreach (DB::table('exchange_requests')->whereNull('request_reference')->get(['id']) as $exchange) {
            do {
                $reference = 'QPMSELL'.Str::upper(Str::random(10));
            } while (DB::table('exchange_requests')->where('request_reference', $reference)->exists());

            DB::table('exchange_requests')->where('id', $exchange->id)->update([
                'request_reference' => $reference,
            ]);
        }

        DB::table('exchange_rates')->where('plan_key', 'base')
            ->where('maximum_amount', '9999.99')
            ->update(['maximum_amount' => '9999.99999999']);
        DB::table('exchange_rates')->where('plan_key', 'prime')
            ->where('maximum_amount', '19999.99')
            ->update(['maximum_amount' => '19999.99999999']);
        DB::table('exchange_rates')->where('plan_key', 'base')
            ->where('description', 'like', '%qualifying deposits%')
            ->update(['description' => 'Applicable for sell amounts below 10,000 USDT.']);
        DB::table('exchange_rates')->where('plan_key', 'base')
            ->where('description', 'Standard USDT to INR reference rate.')
            ->update(['description' => 'Applicable for sell amounts below 10,000 USDT.']);
        DB::table('exchange_rates')->where('plan_key', 'prime')
            ->where('description', 'like', '%qualifying deposits%')
            ->update(['description' => 'Applicable for sell amounts from 10,000 to 19,999.99999999 USDT.']);
        DB::table('exchange_rates')->where('plan_key', 'vip')
            ->where('description', 'like', '%qualifying deposits%')
            ->update(['description' => 'Applicable for sell amounts of 20,000 USDT or more.']);
    }

    public function down(): void
    {
        if (DB::table('exchange_requests')->whereNotNull('available_usd_before')->exists()
            || DB::table('balance_ledger_entries')->whereRaw('amount != ROUND(amount, 2)')->exists()
            || DB::table('users')->whereRaw('balance != ROUND(balance, 2)')->exists()) {
            throw new RuntimeException('Sell workflow or fractional USD accounting data exists; refusing to remove the accounting fields.');
        }

        Schema::table('exchange_requests', function (Blueprint $table): void {
            $table->dropIndex('exchange_rate_plan_created_index');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('rejected_by_user_id');
            $table->dropColumn([
                'rate_plan_key',
                'available_usd_before',
                'approved_at',
                'rejected_at',
                'rejection_reason',
            ]);
        });

        DB::table('exchange_rates')->where('plan_key', 'base')
            ->where('maximum_amount', '9999.99999999')
            ->update(['maximum_amount' => '9999.99']);
        DB::table('exchange_rates')->where('plan_key', 'prime')
            ->where('maximum_amount', '19999.99999999')
            ->update(['maximum_amount' => '19999.99']);
        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->decimal('minimum_amount', 20, 2)->default('0.00')->change();
            $table->decimal('maximum_amount', 20, 2)->nullable()->change();
        });

        Schema::table('balance_ledger_entries', function (Blueprint $table): void {
            $table->decimal('amount', 20, 2)->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('balance', 20, 2)->default('0.00')->change();
        });
    }
};
