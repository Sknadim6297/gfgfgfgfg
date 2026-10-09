<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->select('id', 'customer_id')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    if (is_string($user->customer_id) && preg_match('/^SKNA[0-9]{6}$/D', $user->customer_id)) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['customer_id' => self::generateCustomerId()]);
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
        // Public customer IDs are immutable; the old format cannot be restored safely.
    }
};
