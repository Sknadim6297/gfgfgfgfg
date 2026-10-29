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
            $table->decimal('demo_usdt_balance', 20, 8)->default('0.00000000');
        });

        Schema::table('deposits', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->index();
            $table->string('blockchain_verification_source', 80)->nullable();
        });

        Schema::create('demo_usdt_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('deposit_id')->unique()->constrained('deposits')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 20, 8);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('demo_usdt_ledger_entries')->exists()
            || DB::table('deposits')->where('is_demo', true)->exists()
            || DB::table('deposits')->whereNotNull('blockchain_verification_source')->exists()
            || DB::table('users')->where('demo_usdt_balance', '!=', '0.00000000')->exists()) {
            throw new RuntimeException('Demo deposit records or balances exist; refusing to remove demo wallet history.');
        }

        Schema::dropIfExists('demo_usdt_ledger_entries');

        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropIndex(['is_demo']);
            $table->dropColumn(['is_demo', 'blockchain_verification_source']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('demo_usdt_balance');
        });
    }
};
