<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

class User extends Authenticatable
{
    public const BANK_VERIFICATION_STATUSES = ['not_submitted', 'pending', 'verified', 'rejected'];

    private const BANK_DETAIL_FIELDS = [
        'account_holder_name',
        'bank_name',
        'account_number',
        'ifsc_code',
        'branch_name',
        'account_type',
    ];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $user->customer_id = self::generateCustomerId((string) $user->name);

            if (! $user->referral_code) {
                do {
                    $referralCode = Str::upper(Str::random(12));
                } while (self::query()->where('referral_code', $referralCode)->exists());

                $user->referral_code = $referralCode;
            }
        });

        static::updating(function (self $user): void {
            if ($user->isDirty('customer_id')) {
                throw new LogicException('A customer ID cannot be changed after account creation.');
            }

            if ($user->isDirty('referred_by_user_id')) {
                throw new LogicException('A customer referral assignment cannot be changed after registration.');
            }
        });
    }

    private static function generateCustomerId(string $name): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', Str::ascii($name)) ?? '';
        $prefix = strtoupper(substr($letters, 0, 4));

        // Short prefixes are padded with X; names with no ASCII letters use USER.
        if ($prefix === '') {
            $prefix = 'USER';
        } else {
            $prefix = str_pad($prefix, 4, 'X');
        }

        do {
            $customerId = $prefix.sprintf('%08d', random_int(0, 99999999));
        } while (self::query()->where('customer_id', $customerId)->exists());

        return $customerId;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'mobile',
        'gender',
        'account_holder_name',
        'bank_name',
        'account_number',
        'ifsc_code',
        'branch_name',
        'account_type',
        'usdt_wallet_address',
        'password',
        'role',
        'account_status',
        'email_verified_at',
        'totp_secret',
        'totp_enabled',
        'totp_last_counter',
        'balance',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'wallet_transaction_password_hash',
        'remember_token',
        'totp_secret',
        'demo_usdt_balance',
        'balance',
        'inr_balance',
        'account_holder_name',
        'bank_name',
        'account_number',
        'ifsc_code',
        'branch_name',
        'account_type',
        'usdt_wallet_address',
        'bank_verification_reason',
        'bank_reviewed_by_user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'bank_submitted_at' => 'datetime',
            'bank_verified_at' => 'datetime',
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_enabled' => 'boolean',
            'totp_last_counter' => 'integer',
            'balance' => 'decimal:8',
            'demo_usdt_balance' => 'decimal:8',
            'inr_balance' => 'decimal:2',
            'account_holder_name' => 'encrypted',
            'bank_name' => 'encrypted',
            'account_number' => 'encrypted',
            'ifsc_code' => 'encrypted',
            'branch_name' => 'encrypted',
            'account_type' => 'encrypted',
            'usdt_wallet_address' => 'encrypted',
        ];
    }

    public function exchangeRequests(): HasMany
    {
        return $this->hasMany(ExchangeRequest::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function balanceLedgerEntries(): HasMany
    {
        return $this->hasMany(BalanceLedgerEntry::class);
    }

    public function demoUsdtLedgerEntries(): HasMany
    {
        return $this->hasMany(DemoUsdtLedgerEntry::class);
    }

    public function inrLedgerEntries(): HasMany
    {
        return $this->hasMany(InrLedgerEntry::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    public function referralQualification(): HasOne
    {
        return $this->hasOne(ReferralQualification::class, 'qualified_user_id');
    }

    public function referralCommissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class, 'beneficiary_user_id');
    }

    public function triggeredReferralCommissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class, 'qualified_user_id');
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function fcmDeviceTokens(): HasMany
    {
        return $this->hasMany(FcmDeviceToken::class);
    }

    public function hasCompleteBankDetails(): bool
    {
        foreach (self::BANK_DETAIL_FIELDS as $field) {
            if (! is_string($this->{$field}) || trim($this->{$field}) === '') {
                return false;
            }
        }

        return true;
    }

    public function bankVerificationStatus(): string
    {
        if (! $this->hasCompleteBankDetails()) {
            return 'not_submitted';
        }

        return $this->storedBankVerificationStatus();
    }

    public function bankVerificationStatusForAdminDirectory(): string
    {
        foreach (self::BANK_DETAIL_FIELDS as $field) {
            try {
                $value = $this->{$field};
            } catch (DecryptException) {
                Log::error('Unable to decrypt user bank details in the admin directory.', [
                    'user_id' => $this->getKey(),
                    'attribute' => $field,
                ]);

                return 'data_error';
            }

            if (! is_string($value) || trim($value) === '') {
                return 'not_submitted';
            }
        }

        return $this->storedBankVerificationStatus();
    }

    private function storedBankVerificationStatus(): string
    {
        return in_array($this->bank_verification_status, self::BANK_VERIFICATION_STATUSES, true)
            && $this->bank_verification_status !== 'not_submitted'
            ? $this->bank_verification_status
            : 'pending';
    }

    public function maskedBankAccountNumber(): ?string
    {
        if (! filled($this->account_number)) {
            return null;
        }

        $accountNumber = (string) $this->account_number;

        return str_repeat('•', max(4, mb_strlen($accountNumber) - 4)).mb_substr($accountNumber, -4);
    }

    public function hasWalletTransactionPin(): bool
    {
        return filled($this->wallet_transaction_password_hash);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_user_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_user_id');
    }
}
