<?php

namespace App\Services;

use App\Models\MailFailure;
use Illuminate\Support\Facades\Log;

/**
 * Единая точка записи «письмо не доставлено» — чтобы владелец магазина видел это в
 * админке (Настройки → Уведомления), а не только в storage/logs/laravel.log. Вызывается
 * из двух реальных точек сбоя:
 *  - MailService::dispatch() — не удалось даже поставить письмо в очередь (редко)
 *  - App\Mail\Concerns\RecordsMailFailure::failed() — SMTP отвалился внутри воркера
 *    после исчерпания retry (частый случай — именно он был причиной всей истории)
 */
class MailFailureRecorder
{
    public static function record(array $meta, string $error): void
    {
        try {
            MailFailure::create([...$meta, 'error_message' => substr($error, 0, 1000)]);
        } catch (\Throwable $e) {
            Log::error('Failed to record mail failure', $meta + ['recording_error' => $e->getMessage()]);
        }
    }
}
