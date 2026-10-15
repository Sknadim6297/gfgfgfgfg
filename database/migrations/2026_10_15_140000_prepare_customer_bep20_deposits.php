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
            $table->string('transaction_reference', 150)->nullable()->change();
        });

        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropIndex(['transaction_hash']);
            $table->unique('transaction_hash');
        });
    }

    public function down(): void
    {
        if (DB::table('deposits')->whereNull('transaction_reference')->orWhereNotNull('transaction_hash')->exists()) {
            throw new RuntimeException('Customer BEP20 deposit claims exist; refusing to remove transaction history.');
        }

        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropUnique(['transaction_hash']);
            $table->string('transaction_reference', 150)->nullable(false)->change();
        });

        Schema::table('deposits', function (Blueprint $table): void {
            $table->index('transaction_hash');
        });
    }
};
