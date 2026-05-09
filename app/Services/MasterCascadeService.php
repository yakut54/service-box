<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MasterCascadeService
{
    /**
     * Called when a master is deactivated.
     * Hides services that have no other active masters.
     * Returns names of hidden services (for notification).
     */
    public static function handleDeactivation(string $masterId, string $schema, Shop $shop): array
    {
        // Products linked to this master that have NO other active masters
        $orphaned = DB::select("
            SELECT p.id, p.name
            FROM \"{$schema}\".products p
            JOIN \"{$schema}\".master_services ms ON ms.product_id = p.id
            WHERE ms.master_id = ?
              AND p.is_active = TRUE
              AND p.auto_hidden = FALSE
              AND NOT EXISTS (
                  SELECT 1
                  FROM \"{$schema}\".master_services ms2
                  JOIN \"{$schema}\".masters m ON m.id = ms2.master_id
                  WHERE ms2.product_id = p.id
                    AND ms2.master_id != ?
                    AND m.is_active = TRUE
              )
        ", [$masterId, $masterId]);

        if (empty($orphaned)) {
            return [];
        }

        $ids   = array_column($orphaned, 'id');
        $names = array_column($orphaned, 'name');

        DB::statement(
            "UPDATE \"{$schema}\".products
             SET is_active = FALSE, auto_hidden = TRUE
             WHERE id = ANY(?::uuid[])",
            ['{' . implode(',', $ids) . '}']
        );

        Log::info('MasterCascade: services hidden', [
            'master_id' => $masterId,
            'schema'    => $schema,
            'services'  => $names,
        ]);

        return $names;
    }

    /**
     * Called when a master is reactivated.
     * Restores services that were auto-hidden due to this master.
     * Returns names of restored services (for notification).
     */
    public static function handleReactivation(string $masterId, string $schema, Shop $shop): array
    {
        $restored = DB::select("
            SELECT p.id, p.name
            FROM \"{$schema}\".products p
            JOIN \"{$schema}\".master_services ms ON ms.product_id = p.id
            WHERE ms.master_id = ?
              AND p.auto_hidden = TRUE
        ", [$masterId]);

        if (empty($restored)) {
            return [];
        }

        $ids   = array_column($restored, 'id');
        $names = array_column($restored, 'name');

        DB::statement(
            "UPDATE \"{$schema}\".products
             SET is_active = TRUE, auto_hidden = FALSE
             WHERE id = ANY(?::uuid[])",
            ['{' . implode(',', $ids) . '}']
        );

        Log::info('MasterCascade: services restored', [
            'master_id' => $masterId,
            'schema'    => $schema,
            'services'  => $names,
        ]);

        return $names;
    }

    public static function notifyDeactivation(Shop $shop, array $serviceNames): void
    {
        if (empty($serviceNames)) {
            return;
        }

        $list = implode("\n", array_map(fn ($n) => "• {$n}", $serviceNames));
        $text = "⚠️ <b>Услуги скрыты из виджета</b>\n\n"
              . "Мастер деактивирован. Следующие услуги временно недоступны клиентам:\n"
              . "{$list}\n\n"
              . "Они вернутся автоматически при восстановлении мастера.";

        try {
            TelegramService::sendMessage($shop, $text);
        } catch (\Throwable $e) {
            Log::warning('MasterCascade: telegram notify failed', ['error' => $e->getMessage()]);
        }

        try {
            MaxService::sendMessage($shop, $text);
        } catch (\Throwable $e) {
            Log::warning('MasterCascade: max notify failed', ['error' => $e->getMessage()]);
        }
    }

    public static function notifyReactivation(Shop $shop, array $serviceNames): void
    {
        if (empty($serviceNames)) {
            return;
        }

        $list = implode("\n", array_map(fn ($n) => "• {$n}", $serviceNames));
        $text = "✅ <b>Услуги восстановлены</b>\n\n"
              . "Мастер снова активен. Следующие услуги снова доступны клиентам:\n"
              . "{$list}";

        try {
            TelegramService::sendMessage($shop, $text);
        } catch (\Throwable $e) {
            Log::warning('MasterCascade: telegram notify failed', ['error' => $e->getMessage()]);
        }

        try {
            MaxService::sendMessage($shop, $text);
        } catch (\Throwable $e) {
            Log::warning('MasterCascade: max notify failed', ['error' => $e->getMessage()]);
        }
    }
}
