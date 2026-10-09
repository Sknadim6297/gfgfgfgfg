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
            $table->decimal('inr_balance', 20, 2)->default('0.00');
        });

        Schema::table('balance_ledger_entries', function (Blueprint $table): void {
            $table->foreignId('deposit_id')->nullable()->change();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unique(['source_type', 'source_id', 'entry_type'], 'balance_ledger_source_unique');
        });

        Schema::create('inr_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('entry_type', 20);
            $table->decimal('amount', 20, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source_type', 'source_id', 'entry_type'], 'inr_ledger_source_unique');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('withdrawal_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_reference', 40)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_key');
            $table->decimal('amount', 20, 2);
            $table->text('bank_account_holder');
            $table->text('bank_name');
            $table->text('bank_account_number');
            $table->text('bank_ifsc_code');
            $table->text('bank_branch_name')->nullable();
            $table->text('bank_account_type')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('transaction_reference', 150)->nullable()->unique();
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'submission_key']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('inr_ledger_entries')->exists()
            || DB::table('withdrawal_requests')->exists()
            || DB::table('balance_ledger_entries')->whereNotNull('source_type')->exists()
            || DB::table('users')->where('inr_balance', '!=', '0.00')->exists()) {
            throw new RuntimeException('Wallet accounting data exists; refusing to drop financial balances and ledger history.');
        }

        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('inr_ledger_entries');

        Schema::table('balance_ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('balance_ledger_source_unique');
            $table->dropColumn(['source_type', 'source_id']);
            $table->foreignId('deposit_id')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('inr_balance');
        });
    }
};
