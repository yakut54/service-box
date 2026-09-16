<?php

namespace App\Support;

use App\Models\Shop;

/**
 * «Сейчас ночь по таймзоне магазина» — для поведенческих push (брошенная
 * корзина и т.д.) и позже для промо-рассылок (см. PLAN.md, Шаг 10, которая
 * прямо называет этот хелпер общим и ещё не написанным). Окно — константы,
 * не колонка `shops`: если понадобится настраивать per-shop, менять нужно
 * будет только здесь, а не во всех вызывающих местах.
 */
final class QuietHours
{
    private const START_HOUR = 9;
    private const END_HOUR   = 21;

    public static function isNight(Shop $shop): bool
    {
        $hour = now($shop->timezone ?? 'Europe/Moscow')->hour;

        return $hour < self::START_HOUR || $hour >= self::END_HOUR;
    }
}
