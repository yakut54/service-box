<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Entitlements для «Флота Шоперов» (см. PLAN.md → МФ3/МФ4). Гейтит
 * booking/цифровые товары в приложении — по умолчанию выключено для всех,
 * физические товары всегда доступны и флагом не гейтятся (это база, не опция).
 * Реальных шоперов в проде нет и не было — миграционная осторожность не нужна.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            CREATE TABLE IF NOT EXISTS public.shop_features (
                id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                shop_id     UUID NOT NULL REFERENCES public.shops(id) ON DELETE CASCADE,
                feature_key VARCHAR(255) NOT NULL,
                enabled     BOOLEAN NOT NULL DEFAULT FALSE,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE,
                UNIQUE (shop_id, feature_key)
            )
        ');
        DB::statement('CREATE INDEX IF NOT EXISTS shop_features_shop_id_index ON public.shop_features (shop_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.shop_features');
    }
};
