<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table): void {
            $table->string('recipient_name_snapshot', 120)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('deposits')->whereNotNull('recipient_name_snapshot')->exists()) {
            throw new RuntimeException('Deposit recipient snapshots exist; refusing to remove this payment history.');
        }

        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropColumn('recipient_name_snapshot');
        });
    }
};
