<?php

namespace App\Console\Commands;

use App\Models\CustomerPushToken;
use App\Models\Shop;
use App\Services\TenantService;
use Illuminate\Console\Command;

/**
 * FCM помечает токен просроченным после 270 дней без обновления и начинает
 * отклонять отправки. Чистим раньше и сами — заброшенные токены (переустановка,
 * смена телефона, удаление приложения) иначе копятся мёртвым грузом, как файлы
 * в uploads/ до появления storage:cleanup. last_seen_at обновляется на каждый
 * POST /widget/profile/fcm-token.
 */
class PruneStalePushTokens extends Command
{
    protected $signature   = 'push:prune-stale-tokens {--days=60}';
    protected $description  = 'Delete push tokens not refreshed in N days (default 60)';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $total  = 0;

        foreach (Shop::all() as $shop) {
            $total += TenantService::inContext(
                $shop,
                fn () => CustomerPushToken::where('last_seen_at', '<', $cutoff)->delete(),
            );
        }

        $this->info("Pruned {$total} stale push token(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
