<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $engine = DB::table('information_schema.tables')
            ->where('table_schema', Schema::getConnection()->getDatabaseName())
            ->where('table_name', 'deposit_settings')
            ->value('engine');

        if (is_string($engine) && strcasecmp($engine, 'InnoDB') !== 0) {
            DB::statement('ALTER TABLE deposit_settings ENGINE=InnoDB');
        }
    }

    public function down(): void
    {
        // Keep InnoDB. Row locking is required for deposit settings updates.
    }
};
