<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class MemberNotice extends Model
{
    public const TYPES = ['information', 'important', 'warning', 'maintenance'];

    public const STATUSES = ['draft', 'published', 'inactive'];

    public const PRIORITIES = ['normal', 'high', 'urgent'];

    protected $fillable = [
        'title',
        'description',
        'type',
        'status',
        'priority',
        'publish_at',
        'expires_at',
        'popup_enabled',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'popup_enabled' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeVisibleToMembers(Builder $query, ?Carbon $moment = null): Builder
    {
        $moment ??= now();

        return $query
            ->where('status', 'published')
            ->where(function (Builder $query) use ($moment): void {
                $query->whereNull('publish_at')->orWhere('publish_at', '<=', $moment);
            })
            ->where(function (Builder $query) use ($moment): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $moment);
            });
    }

    public function scopeForPopup(Builder $query, ?Carbon $moment = null): Builder
    {
        return $query->visibleToMembers($moment)->where('popup_enabled', true);
    }

    public function scopeOrderedForDisplay(Builder $query): Builder
    {
        return $query
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 else 2 end")
            ->orderByDesc('publish_at')
            ->orderByDesc('id');
    }

    public function publishedOn(): Carbon
    {
        return $this->publish_at ?? $this->created_at ?? now();
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'important' => 'Important',
            'warning' => 'Warning',
            'maintenance' => 'Maintenance',
            default => 'Information',
        };
    }

    public function priorityLabel(): string
    {
        return match ($this->priority) {
            'urgent' => 'Urgent',
            'high' => 'High',
            default => 'Normal',
        };
    }

    public function iconClass(): string
    {
        return match ($this->type) {
            'important' => 'bi-megaphone',
            'warning' => 'bi-exclamation-triangle',
            'maintenance' => 'bi-tools',
            default => 'bi-info-circle',
        };
    }

    public function availabilityLabel(): string
    {
        $moment = now();

        if ($this->status === 'draft') {
            return 'Draft';
        }

        if ($this->status === 'inactive') {
            return 'Inactive';
        }

        if ($this->publish_at !== null && $this->publish_at->greaterThan($moment)) {
            return 'Scheduled';
        }

        if ($this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($moment)) {
            return 'Expired';
        }

        return 'Live';
    }
}
