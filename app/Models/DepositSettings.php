<?php

namespace App\Models;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositSettings extends Model
{
    /**
     * Singleton primary key assigned by the application. The column is not AUTO_INCREMENT.
     * MySQL and MariaDB return 0 from LAST_INSERT_ID() for that insert, which makes a reload look for the wrong row.
     */
    public $incrementing = false;

    protected $fillable = [
        'id',
        'qr_path',
        'logo_path',
        'recipient_name',
        'instructions',
        'network_name',
        'blockchain',
        'token_contract_address',
        'receiving_wallet_address',
        'minimum_amount',
        'is_enabled',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'minimum_amount' => 'decimal:8',
            'is_enabled' => 'boolean',
        ];
    }

    public static function isManagedQrPath(?string $path): bool
    {
        return is_string($path) && preg_match('~\Adeposits/qr/[A-Za-z0-9._-]+\z~D', $path) === 1;
    }

    public static function isManagedLogoPath(?string $path): bool
    {
        return is_string($path) && preg_match('~\Adeposits/logos/[A-Za-z0-9._-]+\z~D', $path) === 1;
    }

    public function hasActivePaymentSettings(bool $qrImageAvailable): bool
    {
        return $qrImageAvailable
            && filled($this->recipient_name)
            && filled($this->instructions);
    }

    public function hasActiveBep20Settings(bool $logoImageAvailable, bool $qrImageAvailable): bool
    {
        $trustedContractAddress = config('services.bep20.usdt_contract_address');

        return $this->is_enabled
            && $this->network_name === 'BEP20'
            && $this->blockchain === 'BNB Smart Chain'
            && is_string($trustedContractAddress)
            && preg_match('/\A0x[a-fA-F0-9]{40}\z/', $trustedContractAddress) === 1
            && is_string($this->token_contract_address)
            && hash_equals(strtolower($trustedContractAddress), strtolower($this->token_contract_address))
            && preg_match('/\A0x[a-fA-F0-9]{40}\z/', (string) $this->receiving_wallet_address) === 1
            && filled($this->recipient_name)
            && filled($this->instructions)
            && Decimal::compare((string) $this->minimum_amount, '0', 8) > 0
            && $logoImageAvailable
            && $qrImageAvailable;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
