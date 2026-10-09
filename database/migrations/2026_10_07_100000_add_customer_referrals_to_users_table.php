<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('referral_code', 12)->nullable()->unique();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['referred_by_user_id', 'created_at']);
        });

        DB::table('users')->select('id')->whereNull('referral_code')->orderBy('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                do {
                    $code = Str::upper(Str::random(12));
                } while (DB::table('users')->where('referral_code', $code)->exists());

                DB::table('users')->where('id', $user->id)->update(['referral_code' => $code]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['referred_by_user_id']);
            $table->dropIndex(['referred_by_user_id', 'created_at']);
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referred_by_user_id']);
        });
    }
};
