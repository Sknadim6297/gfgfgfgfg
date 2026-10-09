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
            $table->string('request_reference', 40)->nullable()->unique();
            $table->uuid('submission_key')->nullable();
            $table->string('rate_plan_name', 120)->nullable();
            $table->unique(['user_id', 'submission_key']);
        });
    }

    public function down(): void
    {
        if (DB::table('exchange_requests')->whereNotNull('submission_key')->exists()) {
            throw new RuntimeException('Customer exchange requests exist; refusing to drop their historical references and rate-plan snapshots.');
        }

        Schema::table('exchange_requests', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'submission_key']);
            $table->dropUnique(['request_reference']);
            $table->dropColumn(['request_reference', 'submission_key', 'rate_plan_name']);
        });
    }
};
