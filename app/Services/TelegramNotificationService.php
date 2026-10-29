<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    public function sendMessage(string $text): bool
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! is_string($token) || trim($token) === ''
            || ! is_string($chatId) || trim($chatId) === '') {
            Log::warning('Telegram notification skipped: configuration is missing.');

            return false;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(8)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);

            if (! $response->successful() || $response->json('ok') !== true) {
                // Do not log the response body: it may contain sensitive details.
                Log::warning('Telegram notification failed.', [
                    'http_status' => $response->status(),
                ]);

                return false;
            }

            return true;
        } catch (ConnectionException $exception) {
            // Avoid logging the exception message because the request URL
            // contains the bot token.
            Log::warning('Telegram notification connection failed.', [
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
