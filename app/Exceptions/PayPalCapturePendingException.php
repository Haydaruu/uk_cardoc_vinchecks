<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class PayPalCapturePendingException extends RuntimeException
{
    public function __construct(
        string $message = 'PayPal payment has not been captured successfully (status is PENDING).',
        public string $captureStatus = 'PENDING',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getCaptureStatus(): string
    {
        return $this->captureStatus;
    }
}
