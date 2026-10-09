<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile', 20)->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('role', 20)->default('user')->index();
            $table->string('account_status', 20)->default('active')->index();
            $table->string('verification_status', 20)->default('pending')->index();
            $table->text('totp_secret')->nullable();
            $table->boolean('totp_enabled')->default(false);
            $table->unsignedBigInteger('totp_last_counter')->nullable();
        });

        Schema::create('exchange_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('usdt_amount', 20, 8);
            $table->decimal('exchange_rate', 20, 8);
            $table->decimal('inr_amount', 20, 2);
            $table->string('status', 20)->default('pending')->index();
            $table->string('transaction_reference')->nullable()->unique();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('pair', 20)->unique();
            $table->decimal('rate', 20, 8);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->nullableMorphs('auditable');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['subject_user_id', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('exchange_requests');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['account_status']);
            $table->dropIndex(['verification_status']);
            $table->dropColumn([
                'mobile',
                'gender',
                'role',
                'account_status',
                'verification_status',
                'totp_secret',
                'totp_enabled',
                'totp_last_counter',
            ]);
        });
    }
};
