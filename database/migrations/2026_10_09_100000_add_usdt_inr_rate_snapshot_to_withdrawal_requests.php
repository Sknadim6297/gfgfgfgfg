<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->decimal('usdt_inr_rate_snapshot', 20, 8)->nullable()->after('amount');
            $table->index(
                ['user_id', 'status', 'completed_at'],
                'withdrawals_referral_qualification_index'
            );
        });
    }

    public function down(): void
    {
        if (DB::table('withdrawal_requests')->whereNotNull('usdt_inr_rate_snapshot')->exists()) {
            throw new RuntimeException('Withdrawal rate snapshots exist; refusing to remove qualification data.');
        }

        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->dropIndex('withdrawals_referral_qualification_index');
            $table->dropColumn('usdt_inr_rate_snapshot');
        });
    }
};
