<?php

namespace App\Services;

class GuestCaptureResult
{
    public function __construct(
        public readonly string $status, // 'completed', 'payer_action_required', 'funding_declined', 'processing', 'failed'
        public readonly ?string $redirect = null,
        public readonly ?string $payerActionUrl = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $reconciled = false,
        public readonly ?int $httpStatus = null,
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isSuccess(): bool
    {
        return $this->status === 'completed';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isPayerActionRequired(): bool
    {
        return $this->status === 'payer_action_required';
    }

    public function isFundingDeclined(): bool
    {
        return $this->status === 'funding_declined';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
