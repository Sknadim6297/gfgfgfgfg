<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_transfer_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->decimal('minimum_amount', 20, 8)->default('1.00000000');
            $table->decimal('maximum_amount', 20, 8)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('internal_usdt_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_reference', 40)->unique();
            $table->uuid('submission_key');
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 20, 8);
            $table->string('currency', 10)->default('USDT');
            $table->string('status', 20)->default('completed')->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->unique(['sender_user_id', 'submission_key'], 'internal_transfer_sender_submission_unique');
            $table->index(['recipient_user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('internal_usdt_transfers') && DB::table('internal_usdt_transfers')->exists()) {
            throw new RuntimeException('Internal USDT transfers exist; refusing to drop transfer history.');
        }

        if (Schema::hasTable('balance_ledger_entries')
            && DB::table('balance_ledger_entries')->where('source_type', 'internal_usdt_transfer')->exists()) {
            throw new RuntimeException('Internal USDT transfer ledger entries exist; refusing to drop transfer history.');
        }

        Schema::dropIfExists('internal_usdt_transfers');
        Schema::dropIfExists('internal_transfer_settings');
    }
};
