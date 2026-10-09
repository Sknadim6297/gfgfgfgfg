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
            $table->string('wallet_transaction_password_hash')->nullable();
        });

        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->string('payout_method', 20)->default('bank')->after('amount');
            $table->text('bank_account_holder')->nullable()->change();
            $table->text('bank_name')->nullable()->change();
            $table->text('bank_account_number')->nullable()->change();
            $table->text('bank_ifsc_code')->nullable()->change();
        });

        Schema::create('wallet_pin_otps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'consumed_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNotNull('wallet_transaction_password_hash')->exists()) {
            throw new RuntimeException('Configured Wallet Transaction PINs exist; refusing to remove them during rollback.');
        }

        if (Schema::hasTable('wallet_pin_otps') && DB::table('wallet_pin_otps')->whereNotNull('verified_at')->exists()) {
            throw new RuntimeException('Verified Wallet Transaction PIN records exist; refusing to remove their security audit state.');
        }

        if (DB::table('withdrawal_requests')->where('payout_method', 'cash')->exists()) {
            throw new RuntimeException('Cash withdrawal requests exist; refusing to remove their payout method during rollback.');
        }

        Schema::dropIfExists('wallet_pin_otps');
        Schema::table('withdrawal_requests', function (Blueprint $table): void {
            $table->dropColumn('payout_method');
            $table->text('bank_account_holder')->nullable(false)->change();
            $table->text('bank_name')->nullable(false)->change();
            $table->text('bank_account_number')->nullable(false)->change();
            $table->text('bank_ifsc_code')->nullable(false)->change();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('wallet_transaction_password_hash');
        });
    }
};
