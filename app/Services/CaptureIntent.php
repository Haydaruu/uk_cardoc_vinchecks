<?php

namespace App\Services;

enum CaptureIntent: string
{
    case INITIAL_BUYER_CAPTURE = 'initial_buyer_capture';
    case WEBHOOK_CAPTURE = 'webhook_capture';
    case AMBIGUOUS_REPLAY = 'replay_capture';
    case EXPLICIT_FUNDING_RETRY = 'funding_retry';
    case PAYER_ACTION_CONTINUATION = 'payer_action_continuation';
    case RECONCILIATION_GET_ONLY = 'reconciliation_get_only';

    public function allowsPost(): bool
    {
        return $this !== self::RECONCILIATION_GET_ONLY;
    }
}
