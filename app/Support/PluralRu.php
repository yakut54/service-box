<?php

namespace App\Support;

/**
 * Склонение существительного по числу (1 товар / 2 товара / 5 товаров).
 * Стандартное русское правило: 11-14 всегда "many", иначе по последней цифре.
 * Порт mobile/lib/core/format.dart::pluralRu — держать логику идентичной.
 *
 * Два похожих приватных метода уже есть (SendMorningDigest::pluralize,
 * MasterBotService::pluralize), оба захардкожены под «запись» — намеренно не
 * трогаем их в этой задаче, это отдельная уборка.
 */
final class PluralRu
{
    public static function ru(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        return match ($n % 10) {
            1       => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }
}
