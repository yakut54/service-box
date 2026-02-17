<?php

namespace App\Jobs;

use App\Models\TelegramMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // seconds between retries

    public function __construct(
        private readonly string $messageId
    ) {}

    public function handle(): void
    {
        $message = TelegramMessage::find($this->messageId);

        if (!$message || $message->status !== 'pending') {
            return;
        }

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            Log::error('SendTelegramMessage: bot token not configured');
            $message->update(['status' => 'failed', 'error_message' => 'Bot token not configured']);
            return;
        }

        $payload = [
            'chat_id' => $message->telegram_chat_id,
            'text'    => $message->message_text,
            'parse_mode' => 'HTML',
        ];

        if (!empty($message->inline_keyboard)) {
            $payload['reply_markup'] = json_encode([
                'inline_keyboard' => $message->inline_keyboard,
            ]);
        }

        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);

            if ($response->successful() && $response->json('ok')) {
                $sentMessageId = $response->json('result.message_id');
                $message->update([
                    'status' => 'sent',
                    'telegram_message_id' => $sentMessageId,
                    'retry_count' => $this->attempts(),
                    'last_retry_at' => now(),
                    'error_message' => null,
                ]);

                Log::info('Telegram message sent', [
                    'message_id' => $message->id,
                    'telegram_message_id' => $sentMessageId,
                ]);
            } else {
                $error = $response->json('description') ?? 'Unknown Telegram API error';
                $this->handleFailure($message, $error);
            }
        } catch (\Throwable $e) {
            $this->handleFailure($message, $e->getMessage());
            throw $e; // rethrow so queue can retry
        }
    }

    private function handleFailure(TelegramMessage $message, string $error): void
    {
        $message->update([
            'status' => $this->attempts() >= $this->tries ? 'failed' : 'pending',
            'error_message' => $error,
            'retry_count' => $this->attempts(),
            'last_retry_at' => now(),
        ]);

        Log::warning('Telegram message failed', [
            'message_id' => $message->id,
            'attempt' => $this->attempts(),
            'error' => $error,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        TelegramMessage::where('id', $this->messageId)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
    }
}
