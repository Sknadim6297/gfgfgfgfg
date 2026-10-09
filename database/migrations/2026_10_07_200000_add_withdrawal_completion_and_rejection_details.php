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
            $table->timestamp('completed_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });

        DB::table('withdrawal_requests')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('reviewed_at')]);
    }

    public function down(): void
    {
        if (DB::table('withdrawal_requests')->whereNotNull('rejection_reason')->exists()) {
            throw new RuntimeException('Withdrawal rejection reasons exist; refusing to remove customer-visible review history.');
        }

        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->dropColumn(['completed_at', 'rejection_reason']);
        });
    }
};
