<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Deposit extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    public static function temporaryId(): string
    {
        return 'DEP-TEMP-'.(string) Str::ulid();
    }

    protected static function booted(): void
    {
        static::updating(function (self $deposit): void {
            if (! $deposit->isDirty('deposit_id')) {
                return;
            }

            $wasTemporary = str_starts_with((string) $deposit->getRawOriginal('deposit_id'), 'DEP-TEMP-');
            if (! $wasTemporary || preg_match('/\ADEP\d{14,}\z/', (string) $deposit->deposit_id) !== 1) {
                throw new LogicException('A deposit ID cannot be changed after it has been assigned.');
            }
        });
    }

    public function customerStatusLabel(): string
    {
        return match ($this->status) {
            'pending' => ($this->transaction_hash || $this->transaction_reference) ? 'Submitted' : 'Pending Payment',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => ucfirst($this->status),
        };
    }

    protected $fillable = [
        'deposit_id',
        'user_id',
        'submission_key',
        'amount',
        'transaction_reference',
        'proof_path',
        'network_name',
        'blockchain',
        'token_symbol',
        'token_contract_address',
        'receiving_wallet_address',
        'recipient_name_snapshot',
        'network_logo_path',
        'qr_snapshot_path',
        'minimum_amount_snapshot',
        'instructions_snapshot',
        'transaction_hash',
        'transaction_event_index',
        'blockchain_confirmations',
        'blockchain_verified_at',
        'status',
        'rejection_reason',
        'reviewed_by_user_id',
        'submitted_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'minimum_amount_snapshot' => 'decimal:8',
            'transaction_event_index' => 'integer',
            'blockchain_confirmations' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'blockchain_verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(BalanceLedgerEntry::class);
    }
}
