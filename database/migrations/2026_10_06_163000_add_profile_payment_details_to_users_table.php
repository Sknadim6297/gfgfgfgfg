<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('account_holder_name')->nullable();
            $table->text('bank_name')->nullable();
            $table->text('account_number')->nullable();
            $table->text('ifsc_code')->nullable();
            $table->text('branch_name')->nullable();
            $table->text('account_type')->nullable();
            $table->text('usdt_wallet_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'account_holder_name',
                'bank_name',
                'account_number',
                'ifsc_code',
                'branch_name',
                'account_type',
                'usdt_wallet_address',
            ]);
        });
    }
};
