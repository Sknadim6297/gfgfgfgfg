<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_settings', function (Blueprint $table): void {
            $table->string('network_name', 40)->default('BEP20');
            $table->string('blockchain', 80)->default('BNB Smart Chain');
            $table->string('token_contract_address', 42)->nullable();
            $table->string('receiving_wallet_address', 42)->nullable();
            $table->string('logo_path')->nullable();
            $table->decimal('minimum_amount', 20, 8)->default('0.00000000');
            $table->boolean('is_enabled')->default(false);
        });

        Schema::table('deposits', function (Blueprint $table): void {
            $table->string('network_name', 40)->nullable();
            $table->string('blockchain', 80)->nullable();
            $table->string('token_symbol', 20)->nullable();
            $table->string('token_contract_address', 42)->nullable();
            $table->string('receiving_wallet_address', 42)->nullable();
            $table->string('network_logo_path')->nullable();
            $table->string('qr_snapshot_path')->nullable();
            $table->decimal('minimum_amount_snapshot', 20, 8)->nullable();
            $table->text('instructions_snapshot')->nullable();
            $table->string('transaction_hash', 66)->nullable()->index();
            $table->unsignedBigInteger('transaction_event_index')->nullable();
            $table->unsignedInteger('blockchain_confirmations')->nullable();
            $table->timestamp('blockchain_verified_at')->nullable();
        });

        Schema::table('deposits', function (Blueprint $table): void {
            $table->decimal('amount', 20, 8)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('deposits')
            ->whereRaw('amount != ROUND(amount, 2)')
            ->orWhereNotNull('network_name')
            ->orWhereNotNull('blockchain')
            ->orWhereNotNull('token_symbol')
            ->orWhereNotNull('token_contract_address')
            ->orWhereNotNull('receiving_wallet_address')
            ->orWhereNotNull('network_logo_path')
            ->orWhereNotNull('qr_snapshot_path')
            ->orWhereNotNull('minimum_amount_snapshot')
            ->orWhereNotNull('instructions_snapshot')
            ->orWhereNotNull('transaction_hash')
            ->orWhereNotNull('transaction_event_index')
            ->orWhereNotNull('blockchain_confirmations')
            ->orWhereNotNull('blockchain_verified_at')
            ->exists()) {
            throw new RuntimeException('BEP20 deposit data or fractional deposit amounts exist; refusing to remove financial history.');
        }

        if (DB::table('deposit_settings')->where(function ($query): void {
            $query->whereNotNull('token_contract_address')
                ->orWhereNotNull('receiving_wallet_address')
                ->orWhereNotNull('logo_path')
                ->orWhere('minimum_amount', '!=', '0.00000000')
                ->orWhere('is_enabled', true);
        })->exists()) {
            throw new RuntimeException('BEP20 deposit settings exist; refusing to remove the configured settings.');
        }

        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropIndex(['transaction_hash']);
            $table->decimal('amount', 20, 2)->change();
            $table->dropColumn([
                'network_name',
                'blockchain',
                'token_symbol',
                'token_contract_address',
                'receiving_wallet_address',
                'network_logo_path',
                'qr_snapshot_path',
                'minimum_amount_snapshot',
                'instructions_snapshot',
                'transaction_hash',
                'transaction_event_index',
                'blockchain_confirmations',
                'blockchain_verified_at',
            ]);
        });

        Schema::table('deposit_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'network_name',
                'blockchain',
                'token_contract_address',
                'receiving_wallet_address',
                'logo_path',
                'minimum_amount',
                'is_enabled',
            ]);
        });
    }
};
