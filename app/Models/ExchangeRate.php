<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = [
        'pair',
        'plan_key',
        'name',
        'rate',
        'minimum_amount',
        'maximum_amount',
        'label',
        'description',
        'icon',
        'is_active',
        'is_default',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public static function formatDecimal(string|int|float|null $value): string
    {
        $value = (string) $value;
        $formatted = str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;

        return $formatted === '' ? '0' : $formatted;
    }

    public function formattedRate(): string
    {
        return self::formatDecimal($this->rate);
    }

    public function formattedMinimumAmount(): string
    {
        return Money::formatUsdt((string) $this->minimum_amount);
    }

    public function formattedMaximumAmount(): ?string
    {
        if ($this->maximum_amount === null) {
            return null;
        }

        return Money::formatUsdt((string) $this->maximum_amount);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
