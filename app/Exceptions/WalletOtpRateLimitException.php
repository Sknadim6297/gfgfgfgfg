<?php

namespace App\Exceptions;

use RuntimeException;

class WalletOtpRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many verification code requests.');
    }
}
