<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_notification_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience', 24);
            $table->string('title', 100);
            $table->text('message');
            $table->string('destination_url', 2048)->nullable();
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'id']);
        });

        Schema::create('fcm_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('fcm_notification_campaigns')->cascadeOnDelete();
            $table->foreignId('fcm_device_token_id')->nullable()->constrained('fcm_device_tokens')->nullOnDelete();
            $table->char('token_hash', 64);
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('fcm_message_name', 255)->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'token_hash']);
            $table->index(['campaign_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_notification_deliveries');
        Schema::dropIfExists('fcm_notification_campaigns');
    }
};
