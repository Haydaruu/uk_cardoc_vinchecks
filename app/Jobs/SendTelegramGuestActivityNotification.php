<?php

namespace App\Jobs;

use App\Services\TelegramActivityNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SendTelegramGuestActivityNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 3600;

    public function __construct(
        public string $eventType,
        public string $dedupeKey,
        public string $message,
    ) {}

    public function uniqueId(): string
    {
        return strtolower($this->eventType.':'.$this->dedupeKey);
    }

    public function handle(TelegramActivityNotificationService $telegram): void
    {
        if (! $telegram->sendMessage($this->message)) {
            throw new RuntimeException(
                'Unable to deliver the guest activity notification to Telegram.'
            );
        }
    }
}
