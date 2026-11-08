<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class PayPalCaptureDeniedException extends RuntimeException
{
    public function __construct(
        string $message = 'PayPal payment capture was declined or failed.',
        public string $captureStatus = 'DECLINED',
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
