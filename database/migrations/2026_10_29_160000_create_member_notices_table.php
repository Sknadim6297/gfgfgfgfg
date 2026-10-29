<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->text('description');
            $table->string('type', 20);
            $table->string('status', 20)->default('draft');
            $table->string('priority', 20)->default('normal');
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('popup_enabled')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'popup_enabled', 'publish_at', 'expires_at'], 'member_notices_visibility_index');
            $table->index(['priority', 'publish_at'], 'member_notices_priority_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notices');
    }
};
