<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_commission_levels', function (Blueprint $table): void {
            $table->id();
            $table->enum('level', ['1', '2', '3'])->unique();
            $table->decimal('amount', 20, 2);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['id', 'level'], 'referral_levels_id_level_unique');
            $table->index(['is_active', 'level'], 'referral_levels_active_level_index');
        });

        Schema::create('referral_qualifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qualified_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('qualifying_withdrawal_id')->unique()->constrained('withdrawal_requests')->restrictOnDelete();
            $table->decimal('cumulative_usdt_equivalent', 20, 8);
            $table->decimal('threshold_usdt', 20, 8);
            $table->timestamp('qualified_at');
            $table->timestamps();
        });

        Schema::create('referral_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_qualification_id')
                ->constrained('referral_qualifications')
                ->restrictOnDelete();
            $table->foreignId('referral_commission_level_id');
            $table->foreignId('beneficiary_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('qualified_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('level', ['1', '2', '3']);
            $table->decimal('amount', 20, 2);
            $table->json('configuration_snapshot');
            $table->foreignId('triggering_withdrawal_id')
                ->constrained('withdrawal_requests')
                ->restrictOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()
                ->unique()
                ->constrained('inr_ledger_entries')
                ->restrictOnDelete();
            $table->enum('status', ['pending', 'credited', 'reversed'])->default('pending');
            $table->timestamp('qualified_at');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
            $table->foreign(
                ['referral_commission_level_id', 'level'],
                'referral_commissions_level_config_fk'
            )->references(['id', 'level'])->on('referral_commission_levels')->restrictOnDelete();
            $table->unique(
                ['referral_qualification_id', 'level'],
                'referral_commissions_qualification_level_unique'
            );
            $table->unique(
                ['qualified_user_id', 'level'],
                'referral_commissions_customer_level_unique'
            );
            $table->index(
                ['beneficiary_user_id', 'created_at'],
                'referral_commissions_beneficiary_created_index'
            );
            $table->index(
                ['qualified_user_id', 'status'],
                'referral_commissions_customer_status_index'
            );
            $table->index(
                ['level', 'status', 'created_at'],
                'referral_commissions_level_status_created_index'
            );
        });

        $now = now();
        DB::table('referral_commission_levels')->insert([
            ['level' => '1', 'amount' => '1500.00', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level' => '2', 'amount' => '1000.00', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['level' => '3', 'amount' => '500.00', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        if (DB::table('referral_commissions')->exists()
            || DB::table('referral_qualifications')->exists()
            || DB::table('inr_ledger_entries')->where('source_type', 'referral_commission')->exists()) {
            throw new RuntimeException('Referral financial history exists; refusing to remove qualification, commission, or ledger links.');
        }

        Schema::dropIfExists('referral_commissions');
        Schema::dropIfExists('referral_qualifications');
        Schema::dropIfExists('referral_commission_levels');
    }
};
