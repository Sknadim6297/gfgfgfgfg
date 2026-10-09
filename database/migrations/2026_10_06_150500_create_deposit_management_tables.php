<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('balance', 20, 2)->default('0.00');
        });

        Schema::create('deposit_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('qr_path')->nullable();
            $table->string('recipient_name', 120)->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('deposits', function (Blueprint $table): void {
            $table->id();
            $table->string('deposit_id', 40)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_key');
            $table->decimal('amount', 20, 2);
            $table->string('transaction_reference', 150)->unique();
            $table->string('proof_path')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'submission_key']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('balance_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('deposit_id')->unique()->constrained('deposits')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entry_type', 20)->default('credit');
            $table->decimal('amount', 20, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_ledger_entries');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('deposit_settings');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('balance');
        });
    }
};
