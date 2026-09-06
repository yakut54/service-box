<?php

namespace App\Support;

/**
 * Деньги в проекте хранятся в копейках (integer). Единственная точка
 * форматирования «копейки → строка целых рублей» — чтобы баг «забыли поделить
 * на 100» не расползался по копиям. Он реально жил в TelegramService и
 * MaxService (суммы заказа/доставки/услуги показывались в 100 раз больше) до
 * 2026-09-06.
 *
 * Формат совпадает с фронтом (widget/src/lib/utils.ts formatPrice,
 * admin/src/shared/lib/format.ts) и письмами (MailService::rubles делегирует
 * сюда): целые рубли, разряды через пробел, без знака валюты.
 */
final class Money
{
    /** Копейки → «1 500». null трактуется как 0. */
    public static function rubles(?int $kopecks): string
    {
        return number_format((float) round(($kopecks ?? 0) / 100), 0, '.', ' ');
    }
}
