<?php

namespace App\Policies;

use App\Models\Deposit;
use App\Models\User;

class DepositPolicy
{
    public function view(User $user, Deposit $deposit): bool
    {
        return $user->role === 'user' && $deposit->user_id === $user->id;
    }
}
