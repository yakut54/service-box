<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\MaxService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendMorningDigest extends Command
{
    protected $signature   = 'bookings:morning-digest';
    protected $description = 'Send today\'s booking schedule to shop owners at 08:00 in their timezone';

    public function handle(): int
    {
        $shops = Shop::where(function ($q) {
            $q->where('telegram_bot_connected', true)
              ->orWhere('max_bot_connected', true);
        })->get();

        foreach ($shops as $shop) {
            $this->processShop($shop);
        }

        return self::SUCCESS;
    }

    private function processShop(Shop $shop): void
    {
        $tz  = $shop->timezone ?? 'Europe/Moscow';
        $now = Carbon::now($tz);

        // Fire only in the 08:00–08:14 window in the shop's own timezone
        if ($now->hour !== 8 || $now->minute > 14) {
            return;
        }

        $today    = $now->toDateString();
        $cacheKey = "morning_digest:{$shop->id}:{$today}";

        if (Cache::has($cacheKey)) {
            return;
        }

        $s = $shop->schema_name;

        $bookings = DB::select("
            SELECT
                b.start_time,
                b.customer_name,
                b.customer_phone,
                p.name AS service_name,
                m.name AS master_name
            FROM {$s}.bookings b
            LEFT JOIN {$s}.products p ON p.id = b.service_id
            LEFT JOIN {$s}.masters  m ON m.id = b.master_id
            WHERE b.status IN ('pending', 'confirmed')
              AND DATE(b.start_time AT TIME ZONE :tz) = :today
            ORDER BY b.start_time
        ", ['tz' => $tz, 'today' => $today]);

        $text = $this->buildText($bookings, $now, $tz);

        try {
            if ($shop->telegram_bot_connected) {
                TelegramService::sendMessage(shop: $shop, text: $text);
            }
            if ($shop->max_bot_connected) {
                MaxService::sendMessage($shop, $text);
            }

            Cache::put($cacheKey, true, now()->addHours(20));

            Log::info('Morning digest sent', ['shop' => $shop->id, 'count' => count($bookings)]);
        } catch (\Throwable $e) {
            Log::error('Morning digest failed', ['shop' => $shop->id, 'error' => $e->getMessage()]);
        }
    }

    private function buildText(array $bookings, Carbon $now, string $tz): string
    {
        $dateLabel = $now->locale('ru')->translatedFormat('j M, D');

        if (empty($bookings)) {
            return "📅 <b>Расписание на {$dateLabel}</b>\n\nЗаписей нет. Свободный день! 🎉";
        }

        $text = "📅 <b>Расписание на {$dateLabel}</b>\n\n";

        foreach ($bookings as $i => $b) {
            $time    = Carbon::parse($b->start_time)->setTimezone($tz)->format('H:i');
            $service = $b->service_name ?? '—';
            $master  = $b->master_name ? " · 👤 {$b->master_name}" : '';
            $n       = $i + 1;

            $text .= "{$n}. 🕐 <b>{$time}</b> — {$service}{$master}\n";
            $text .= "   {$b->customer_name}  {$b->customer_phone}\n\n";
        }

        $count = count($bookings);
        $text .= "Всего: <b>{$count}</b> " . $this->pluralize($count);

        return $text;
    }

    private function pluralize(int $n): string
    {
        if ($n % 10 === 1 && $n % 100 !== 11) return 'запись';
        if ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) return 'записи';
        return 'записей';
    }
}
