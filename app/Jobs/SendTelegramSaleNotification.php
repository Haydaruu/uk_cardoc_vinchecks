<?php

namespace App\Jobs;

use App\Services\TelegramNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SendTelegramSaleNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 3600;

    public function __construct(
        public string $saleType,
        public string $paymentProvider,
        public string $reference,
        public string $amount,
    ) {}

    public function uniqueId(): string
    {
        return strtolower($this->paymentProvider.':'.$this->reference);
    }

    public function handle(TelegramNotificationService $telegram): void
    {
        $message = implode("\n", [
            'NEW VINCheck SALE',
            '',
            'Type: '.$this->saleType,
            'Payment: '.$this->paymentProvider,
            'Reference: '.$this->reference,
            'Amount: '.$this->amount,
            'Status: Paid',
        ]);

        if (! $telegram->sendMessage($message)) {
            throw new RuntimeException(
                'Unable to deliver the VinCheck sales notification to Telegram.'
            );
        }
    }
}
