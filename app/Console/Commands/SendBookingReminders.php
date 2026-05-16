<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\MaxService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendBookingReminders extends Command
{
    protected $signature   = 'bookings:send-reminders';
    protected $description = 'Send Telegram reminders to customers 24h and 2h before confirmed bookings';

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
        $s = $shop->schema_name;

        Log::info('[Reminders] processing shop', [
            'shop'      => $shop->id,
            'schema'    => $s,
            'tg'        => $shop->telegram_bot_connected,
            'max'       => $shop->max_bot_connected,
        ]);

        foreach (['24h' => [23, 45, 24, 15], '2h' => [1, 45, 2, 15]] as $type => [$hFrom, $mFrom, $hTo, $mTo]) {
            $flag = $type === '24h' ? 'reminder_24h_sent' : 'reminder_2h_sent';

            $bookings = DB::select("
                SELECT
                    b.id, b.start_time, b.customer_phone, b.customer_name,
                    s.name  AS service_name,
                    m.name  AS master_name
                FROM {$s}.bookings b
                LEFT JOIN {$s}.products s ON s.id = b.service_id
                LEFT JOIN {$s}.masters  m ON m.id = b.master_id
                WHERE b.status = 'confirmed'
                  AND b.{$flag} = FALSE
                  AND b.start_time BETWEEN
                        NOW() + INTERVAL '{$hFrom} hours {$mFrom} minutes'
                    AND NOW() + INTERVAL '{$hTo} hours {$mTo} minutes'
            ");

            Log::info("[Reminders] [{$type}] found " . count($bookings) . " booking(s)", ['shop' => $shop->id]);

            foreach ($bookings as $booking) {
                Log::info("[Reminders] [{$type}] sending to {$booking->customer_name} ({$booking->customer_phone})", [
                    'booking'    => $booking->id,
                    'start_time' => $booking->start_time,
                ]);
                try {
                    if ($shop->telegram_bot_connected) {
                        TelegramService::notifyBookingReminder($shop, $booking, $type);
                    }
                    if ($shop->max_bot_connected) {
                        MaxService::notifyBookingReminder($booking->id, $booking, $type, $shop->timezone ?? 'Europe/Moscow');
                    }

                    DB::statement("UPDATE {$s}.bookings SET {$flag} = TRUE WHERE id = ?", [$booking->id]);

                    Log::info("[Reminders] [{$type}] sent OK", ['shop' => $shop->id, 'booking' => $booking->id]);
                } catch (\Throwable $e) {
                    Log::error("[Reminders] [{$type}] FAILED", [
                        'shop'    => $shop->id,
                        'booking' => $booking->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        // Rating request after booking end_time
        $ratingBookings = DB::select("
            SELECT
                b.id, b.end_time, b.customer_phone, b.customer_name,
                s.name AS service_name
            FROM {$s}.bookings b
            LEFT JOIN {$s}.products s ON s.id = b.service_id
            WHERE b.status = 'completed'
              AND b.rating_sent = FALSE
              AND b.updated_at BETWEEN
                    NOW() - INTERVAL '7 minutes'
                AND NOW() - INTERVAL '3 minutes'
        ");

        Log::info('[Reminders] [rating] found ' . count($ratingBookings) . ' booking(s)', ['shop' => $shop->id]);

        foreach ($ratingBookings as $booking) {
            Log::info("[Reminders] [rating] sending to {$booking->customer_name} ({$booking->customer_phone})", [
                'booking'  => $booking->id,
                'end_time' => $booking->end_time,
            ]);
            try {
                if ($shop->telegram_bot_connected) {
                    TelegramService::notifyRatingRequest($shop, $booking);
                }
                if ($shop->max_bot_connected) {
                    MaxService::notifyRatingRequest($booking->id, $booking);
                }

                DB::statement("UPDATE {$s}.bookings SET rating_sent = TRUE WHERE id = ?", [$booking->id]);

                Log::info('[Reminders] [rating] sent OK', ['shop' => $shop->id, 'booking' => $booking->id]);
            } catch (\Throwable $e) {
                Log::error('[Reminders] [rating] FAILED', [
                    'shop'    => $shop->id,
                    'booking' => $booking->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
