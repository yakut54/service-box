<?php

namespace App\Console\Commands;

use App\Models\MailFailure;
use App\Models\Shop;
use App\Services\MailFailureRecorder;
use Illuminate\Console\Command;

/**
 * Раз в день проверяет, не остался ли драйвер почты на дефолтном 'log' в проде —
 * это ровно тот сценарий из-за которого письма месяцами тихо не отправлялись (см.
 * PLAN.md, «Email-уведомления шоперу и байеру»): driver 'log' никогда не бросает
 * исключение, поэтому обычный перехват ошибок отправки (MailService::dispatch,
 * RecordsMailFailure::failed) его не ловит вообще — нужна отдельная проверка.
 *
 * Пишет по одной записи 'config' на магазин в mail_failures, не чаще раза в день —
 * чтобы владелец увидел проблему в админке, а не только в логе сервера.
 */
class CheckMailConfiguration extends Command
{
    protected $signature = 'mail:check-configuration';

    protected $description = 'Flag every shop if the mail driver is still the no-op "log" default in production';

    public function handle(): void
    {
        if (!app()->environment('production') || config('mail.default') !== 'log') {
            return;
        }

        $today = now()->startOfDay();

        Shop::chunk(100, function ($shops) use ($today) {
            foreach ($shops as $shop) {
                $flaggedToday = MailFailure::forShop($shop->id)
                    ->where('entity_type', 'config')
                    ->where('created_at', '>=', $today)
                    ->exists();

                if (!$flaggedToday) {
                    MailFailureRecorder::record([
                        'shop_id' => $shop->id,
                        'entity_type' => 'config',
                        'entity_id' => null,
                        'recipient_type' => 'platform',
                        'recipient_email' => null,
                    ], 'Почтовый сервер не настроен — письма не отправляются по-настоящему (тестовый log-режим).');
                }
            }
        });
    }
}
