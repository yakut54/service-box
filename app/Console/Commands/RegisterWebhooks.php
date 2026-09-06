<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Наводит вебхуки Telegram и MAX на текущий домен (config('app.url')).
 * Идемпотентна — сверяет уже установленный URL и трогает только при
 * расхождении, поэтому deploy.sh зовёт её каждый деплой.
 *
 * Раньше: Telegram setWebhook не автоматизирован нигде (руками через API),
 * MAX — curl в deploy.sh с захардкоженными доменом и секретом.
 */
class RegisterWebhooks extends Command
{
    protected $signature  = 'webhooks:register {--force : Переустановить, даже если URL совпадает}';
    protected $description = 'Point the Telegram and MAX bot webhooks at the current APP_URL';

    public function handle(): int
    {
        $base = rtrim((string) config('app.url'), '/');

        if (!str_starts_with($base, 'https://')) {
            $this->error("app.url ('{$base}') не https:// — вебхуки не регистрирую.");
            return self::FAILURE;
        }

        $this->syncTelegram($base);
        $this->syncMax($base);

        return self::SUCCESS;
    }

    private function syncTelegram(string $base): void
    {
        $token = config('services.telegram.bot_token');
        if (!$token) {
            $this->warn('[Telegram] bot_token не задан — пропуск.');
            return;
        }

        $want   = "{$base}/api/webhook/telegram";
        $secret = config('services.telegram.secret_token');

        $current = Http::timeout(10)
            ->get("https://api.telegram.org/bot{$token}/getWebhookInfo")
            ->json('result.url');

        if ($current === $want && !$this->option('force')) {
            $this->info("[Telegram] уже на {$want}");
            return;
        }

        $payload = ['url' => $want, 'allowed_updates' => ['message', 'callback_query']];
        if ($secret) {
            $payload['secret_token'] = $secret;
        }

        $res = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/setWebhook", $payload);

        if ($res->json('ok')) {
            $this->info("[Telegram] webhook → {$want}");
            Log::info('Telegram webhook set', ['url' => $want]);
        } else {
            $this->error('[Telegram] setWebhook: ' . $res->body());
            Log::error('Telegram setWebhook failed', ['body' => $res->body()]);
        }
    }

    private function syncMax(string $base): void
    {
        $token  = config('services.max.bot_token');
        $secret = config('services.max.webhook_secret');
        if (!$token || !$secret) {
            $this->warn('[MAX] bot_token / webhook_secret не задан — пропуск.');
            return;
        }

        $api  = rtrim((string) config('services.max.api_url', 'https://platform-api.max.ru'), '/');
        $want = "{$base}/api/webhook/max/{$secret}";

        $subs = Http::timeout(10)
            ->withHeaders(['Authorization' => $token])
            ->get("{$api}/subscriptions")
            ->json('subscriptions');

        $already = is_array($subs)
            && collect($subs)->contains(fn ($s) => ($s['url'] ?? null) === $want);

        if ($already && !$this->option('force')) {
            $this->info("[MAX] уже на {$want}");
            return;
        }

        $res = Http::timeout(10)
            ->withHeaders(['Authorization' => $token])
            ->post("{$api}/subscriptions", [
                'url'          => $want,
                'update_types' => ['message_created', 'message_callback', 'bot_started'],
            ]);

        if ($res->successful() && $res->json('ok') !== false) {
            $this->info("[MAX] webhook → {$want}");
            Log::info('MAX webhook set', ['url' => $want]);
        } else {
            $this->error('[MAX] subscriptions: ' . $res->body());
            Log::error('MAX subscribe failed', ['body' => $res->body()]);
        }
    }
}
