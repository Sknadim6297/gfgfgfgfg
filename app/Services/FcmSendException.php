<?php

namespace App\Services;

use RuntimeException;

class FcmSendException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly bool $retryable,
        public readonly bool $invalidToken = false,
    ) {
        parent::__construct('The push notification could not be sent.');
    }
}
