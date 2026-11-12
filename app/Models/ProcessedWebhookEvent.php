<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedWebhookEvent extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MANUAL_REVIEW = 'manual_review';

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'status',
        'ownership_token',
        'retry_count',
        'next_retry_at',
        'error_message',
        'payload_summary',
        'processed_at',
    ];

    protected $casts = [
        'payload_summary' => 'array',
        'processed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isManualReview(): bool
    {
        return $this->status === self::STATUS_MANUAL_REVIEW;
    }
}
