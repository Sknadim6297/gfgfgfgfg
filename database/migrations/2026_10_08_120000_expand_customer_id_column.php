<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('customer_id', 12)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereRaw('CHAR_LENGTH(customer_id) > 11')->exists()) {
            throw new RuntimeException('Customer IDs longer than 11 characters exist; refusing to truncate them.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('customer_id', 11)->nullable()->change();
        });
    }
};
