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
            $table->string('customer_id', 11)->nullable()->unique();
        });

        DB::table('users')->select('id')->whereNull('customer_id')->orderBy('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['customer_id' => self::generateCustomerId()]);
            }
        });
    }

    private static function generateCustomerId(): string
    {
        do {
            $customerId = 'SKNA'.sprintf('%06d', random_int(0, 999999));
        } while (DB::table('users')->where('customer_id', $customerId)->exists());

        return $customerId;
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
