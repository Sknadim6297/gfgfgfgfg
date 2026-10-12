<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_requests', function (Blueprint $table): void {
            $table->timestamp('referral_eligible_at')->nullable();
            $table->index(['user_id', 'status', 'completed_at'], 'exchange_referral_sales_progress_index');
        });

        Schema::table('referral_qualifications', function (Blueprint $table): void {
            $table->foreignId('qualifying_withdrawal_id')->nullable()->change();
            $table->foreignId('qualifying_exchange_request_id')
                ->nullable()
                ->unique()
                ->constrained('exchange_requests')
                ->restrictOnDelete();
        });

        Schema::table('referral_commissions', function (Blueprint $table): void {
            $table->foreignId('triggering_withdrawal_id')->nullable()->change();
            $table->foreignId('triggering_exchange_request_id')
                ->nullable()
                ->constrained('exchange_requests')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('referral_qualifications')->whereNotNull('qualifying_exchange_request_id')->exists()
            || DB::table('referral_commissions')->whereNotNull('triggering_exchange_request_id')->exists()) {
            throw new RuntimeException('Sell-based referral qualifications exist; refusing to remove their source records.');
        }

        Schema::table('referral_commissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('triggering_exchange_request_id');
            $table->foreignId('triggering_withdrawal_id')->nullable(false)->change();
        });

        Schema::table('referral_qualifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('qualifying_exchange_request_id');
            $table->foreignId('qualifying_withdrawal_id')->nullable(false)->change();
        });

        Schema::table('exchange_requests', function (Blueprint $table): void {
            $table->dropIndex('exchange_referral_sales_progress_index');
            $table->dropColumn('referral_eligible_at');
        });
    }
};
