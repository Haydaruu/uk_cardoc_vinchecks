<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramActivityNotificationService
{
    public function sendMessage(string $text): bool
    {
        $token = config('services.telegram_activity.bot_token');
        $chatId = config('services.telegram_activity.chat_id');

        if (
            ! is_string($token) || trim($token) === ''
            || ! is_string($chatId) || trim($chatId) === ''
        ) {
            Log::warning(
                'Telegram activity notification skipped: configuration is missing.'
            );

            return false;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(8)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);

            if (
                ! $response->successful()
                || $response->json('ok') !== true
            ) {
                Log::warning('Telegram activity notification failed.', [
                    'http_status' => $response->status(),
                    'telegram_error' => $response->json('description') ?? 'No description returned',
                ]);

                return false;
            }

            return true;
        } catch (ConnectionException $exception) {
            // Avoid logging the exception message because the URL contains the bot token.
            Log::warning('Telegram activity connection failed.', [
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
