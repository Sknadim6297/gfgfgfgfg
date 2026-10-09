<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('bank_verification_status', 20)->default('not_submitted')->index();
            $table->text('bank_verification_reason')->nullable();
            $table->timestamp('bank_submitted_at')->nullable();
            $table->timestamp('bank_verified_at')->nullable();
            $table->foreignId('bank_reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        User::query()
            ->whereNotNull('account_number')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    if ($user->hasCompleteBankDetails()) {
                        $user->forceFill([
                            'bank_verification_status' => 'pending',
                            'bank_submitted_at' => $user->updated_at ?? now(),
                        ])->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['bank_reviewed_by_user_id']);
            $table->dropIndex(['bank_verification_status']);
            $table->dropColumn([
                'bank_verification_status',
                'bank_verification_reason',
                'bank_submitted_at',
                'bank_verified_at',
                'bank_reviewed_by_user_id',
            ]);
        });
    }
};
