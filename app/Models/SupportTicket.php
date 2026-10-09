<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    public const CATEGORIES = [
        'Deposit',
        'Withdrawal',
        'Exchange',
        'Wallet',
        'Bank Details',
        'Account',
        'Technical Issue',
        'Other',
    ];

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'ticket_number',
        'user_id',
        'guest_name',
        'guest_email',
        'subject',
        'category',
        'status',
        'priority',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }
}
