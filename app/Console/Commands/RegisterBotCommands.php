<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterBotCommands extends Command
{
    protected $signature   = 'bots:register-commands';
    protected $description = 'Register master commands (/today, /week, /stats) in Telegram and MAX bots';

    // Telegram format: {command, description}
    private const TG_COMMANDS = [
        ['command' => 'today', 'description' => 'Мои записи на сегодня'],
        ['command' => 'week',  'description' => 'Мои записи на неделю'],
        ['command' => 'stats', 'description' => 'Статистика за текущий месяц'],
    ];

    // MAX format: {name, description}
    private const MAX_COMMANDS = [
        ['name' => 'today', 'description' => 'Мои записи на сегодня'],
        ['name' => 'week',  'description' => 'Мои записи на неделю'],
        ['name' => 'stats', 'description' => 'Статистика за текущий месяц'],
    ];

    public function handle(): int
    {
        $this->registerTelegram();
        $this->registerMax();
        return self::SUCCESS;
    }

    private function registerTelegram(): void
    {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            $this->warn('[Telegram] Bot token not configured, skipping.');
            return;
        }

        // Register for all private chats (masters use private chats)
        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$token}/setMyCommands",
            [
                'commands' => self::TG_COMMANDS,
                'scope'    => ['type' => 'all_private_chats'],
            ]
        );

        if ($response->json('ok')) {
            $this->info('[Telegram] Commands registered: /today, /week, /stats');
            Log::info('Telegram bot commands registered');
        } else {
            $this->error('[Telegram] Failed: ' . $response->body());
            Log::error('Telegram register commands failed', ['body' => $response->body()]);
        }
    }

    private function registerMax(): void
    {
        $token = config('services.max.bot_token');

        if (!$token) {
            $this->warn('[MAX] Bot token not configured, skipping.');
            return;
        }

        $apiUrl = config('services.max.api_url', 'https://platform-api.max.ru');

        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => $token])
            ->patch("{$apiUrl}/me", [
                'commands' => self::MAX_COMMANDS,
            ]);

        if ($response->successful()) {
            $this->info('[MAX] Commands registered: /today, /week, /stats');
            Log::info('MAX bot commands registered');
        } else {
            $this->error('[MAX] Failed: ' . $response->body());
            Log::error('MAX register commands failed', ['body' => $response->body()]);
        }
    }
}
