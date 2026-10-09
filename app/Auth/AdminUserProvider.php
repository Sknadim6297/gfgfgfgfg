<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;

class AdminUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);

        return $user?->role === 'admin' ? $user : null;
    }

    public function retrieveByToken($identifier, $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $user?->role === 'admin' ? $user : null;
    }

    public function retrieveByCredentials(array $credentials)
    {
        $user = parent::retrieveByCredentials($credentials);

        return $user?->role === 'admin' ? $user : null;
    }
}
