<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ShopStaff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\TelegramService;
use App\Services\MaxService;

/**
 * Shared logic for master bot commands (Telegram + MAX).
 * Builds schedule and stats messages, looks up masters.
 */
class MasterBotService
{
    // ── Master lookup ─────────────────────────────────────────────────────

    public static function findByTelegramChatId(int $chatId): ?array
    {
        $staff = ShopStaff::where('telegram_chat_id', $chatId)
            ->where('role', 'master')
            ->whereNotNull('master_id')
            ->first();

        return $staff ? self::buildContext($staff) : null;
    }

    public static function findByMaxUserId(int $userId): ?array
    {
        $staff = ShopStaff::where('max_user_id', $userId)
            ->where('role', 'master')
            ->whereNotNull('master_id')
            ->first();

        return $staff ? self::buildContext($staff) : null;
    }

    private static function buildContext(ShopStaff $staff): array
    {
        $shop = Shop::find($staff->shop_id);
        return [
            'staff'     => $staff,
            'shop'      => $shop,
            'schema'    => $shop?->schema_name,
            'master_id' => $staff->master_id,
            'tz'        => $shop?->timezone ?? 'Europe/Moscow',
            'hide_phone'=> (bool) ($shop?->hide_customer_phone ?? false),
        ];
    }

    // ── Schedule ──────────────────────────────────────────────────────────

    public static function getScheduleText(
        string $schema,
        string $masterId,
        string $tz,
        string $period = 'today',
        bool   $hidePhone = false
    ): string {
        $s = '"' . $schema . '"';

        if ($period === 'today') {
            $date     = Carbon::now($tz)->toDateString();
            $bookings = DB::select("
                SELECT b.start_time, b.customer_name, b.customer_phone, p.name AS service_name
                FROM {$s}.bookings b
                LEFT JOIN {$s}.products p ON p.id = b.service_id
                WHERE b.master_id = ?
                  AND b.status IN ('pending','confirmed')
                  AND DATE(b.start_time AT TIME ZONE ?) = ?
                ORDER BY b.start_time
            ", [$masterId, $tz, $date]);

            $label = Carbon::now($tz)->locale('ru')->translatedFormat('j M, D');
            $title = "📅 <b>Расписание на сегодня, {$label}</b>";
        } else {
            // next 7 days including today
            $from = Carbon::now($tz)->startOfDay()->utc();
            $to   = Carbon::now($tz)->addDays(7)->startOfDay()->utc();
            $bookings = DB::select("
                SELECT b.start_time, b.customer_name, b.customer_phone, p.name AS service_name
                FROM {$s}.bookings b
                LEFT JOIN {$s}.products p ON p.id = b.service_id
                WHERE b.master_id = ?
                  AND b.status IN ('pending','confirmed')
                  AND b.start_time >= ? AND b.start_time < ?
                ORDER BY b.start_time
            ", [$masterId, $from->toDateTimeString(), $to->toDateTimeString()]);

            $title = '📅 <b>Расписание на неделю</b>';
        }

        if (empty($bookings)) {
            return $title . "\n\nЗаписей нет. Свободный день! 🎉";
        }

        $text        = $title . "\n\n";
        $currentDate = null;
        $n           = 1;

        foreach ($bookings as $b) {
            $dt      = Carbon::parse($b->start_time)->setTimezone($tz);
            $dateStr = $dt->toDateString();

            if ($period === 'week' && $dateStr !== $currentDate) {
                $currentDate = $dateStr;
                $text .= '<b>' . $dt->locale('ru')->translatedFormat('j M, D') . "</b>\n";
            }

            $time     = $dt->format('H:i');
            $service  = self::esc($b->service_name ?? '—');
            $customer = self::esc($b->customer_name);

            $text .= "{$n}. 🕐 <b>{$time}</b> — {$service}\n";
            $text .= "   👤 {$customer}";
            if (!$hidePhone && !empty($b->customer_phone)) {
                $text .= ' · 📞 ' . self::esc($b->customer_phone);
            }
            $text .= "\n";
            $n++;
        }

        $count = count($bookings);
        $text .= "\nВсего: <b>{$count}</b> " . self::pluralize($count);

        return $text;
    }

    // ── Stats ─────────────────────────────────────────────────────────────

    public static function getStatsText(string $schema, string $masterId, string $tz): string
    {
        $s = '"' . $schema . '"';

        $master = DB::selectOne(
            "SELECT salary_type, salary_rate FROM {$s}.masters WHERE id = ?",
            [$masterId]
        );

        $from = Carbon::now($tz)->startOfMonth()->utc()->toDateTimeString();
        $to   = Carbon::now($tz)->endOfMonth()->endOfDay()->utc()->toDateTimeString();

        $rows = DB::select("
            SELECT b.status, COALESCE(p.price, 0) AS service_price
            FROM {$s}.bookings b
            LEFT JOIN {$s}.products p ON p.id = b.service_id
            WHERE b.master_id = ? AND b.start_time BETWEEN ? AND ?
        ", [$masterId, $from, $to]);

        $total     = count($rows);
        $completed = array_filter($rows, fn($r) => $r->status === 'completed');
        $noShow    = count(array_filter($rows, fn($r) => $r->status === 'no_show'));

        $salaryRate = (float) ($master->salary_rate ?? 0);
        $salaryType = $master->salary_type ?? 'percent';
        $earnings   = 0.0;

        if ($salaryRate > 0) {
            foreach ($completed as $r) {
                $earnings += $salaryType === 'fixed'
                    ? $salaryRate
                    : (int) $r->service_price * $salaryRate / 100;
            }
        }

        $monthLabel     = Carbon::now($tz)->locale('ru')->translatedFormat('F Y');
        $completedCount = count($completed);

        $text  = "📊 <b>Статистика за {$monthLabel}</b>\n\n";
        $text .= "📋 Всего записей: <b>{$total}</b>\n";
        $text .= "✅ Выполнено: <b>{$completedCount}</b>\n";
        $text .= "👻 Неявок: <b>{$noShow}</b>\n";

        if ($salaryRate > 0) {
            $fmt   = number_format($earnings, 0, '.', ' ');
            $hint  = $salaryType === 'fixed' ? "{$salaryRate} ₽ за визит" : "{$salaryRate}% от услуги";
            $text .= "\n💰 Заработано: <b>{$fmt} ₽</b>\n   ({$hint})";
        } else {
            $text .= "\n💰 Ставка не задана — обратитесь к владельцу";
        }

        return $text;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Notify shopper (TG+MAX) and master (TG+MAX) about a new/updated review.
     * Single entry point — called from both TelegramController and MaxController.
     */
    public static function notifyAllOnReview(
        string $schema,
        string $customerName,
        string $serviceName,
        ?string $masterId,
        int $score,
        ?string $text,
        ?string $reviewId = null
    ): void {
        $shop = Shop::where('schema_name', $schema)->first();
        if (!$shop) return;

        try { TelegramService::notifyOwnerReview($shop, $customerName, $serviceName, $score, $text, $reviewId); } catch (\Throwable) {}
        try { MaxService::notifyOwnerReview($shop, $customerName, $serviceName, $score, $text, $reviewId); } catch (\Throwable) {}

        if (!$masterId) return;

        $masterStaff = ShopStaff::where('master_id', $masterId)
            ->where('shop_id', $shop->id)
            ->whereNotNull('accepted_at')
            ->first();

        if (!$masterStaff) return;

        if ($masterStaff->telegram_chat_id) {
            try { TelegramService::notifyMasterReview($masterStaff->telegram_chat_id, $customerName, $score, $text); } catch (\Throwable) {}
        }
        if ($masterStaff->max_user_id) {
            try { MaxService::notifyMasterReview($masterStaff->max_user_id, $customerName, $score, $text); } catch (\Throwable) {}
        }
    }

    private static function pluralize(int $n): string
    {
        if ($n % 10 === 1 && $n % 100 !== 11) return 'запись';
        if ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) return 'записи';
        return 'записей';
    }
}
