<?php

namespace App\Services;

use App\Models\PushFailure;
use Illuminate\Support\Facades\Log;

/**
 * Единая точка записи «push не доставлен» — по образцу MailFailureRecorder.
 * Вызывается из FirebaseService, когда токен оказался мёртвым (FCM 404) или
 * шлюз вернул устойчивую ошибку. Само по себе не бросает — это best-effort
 * побочная запись рядом с основным флоу.
 */
class PushFailureRecorder
{
    public static function record(array $meta, string $error, bool $tokenInvalidated = false): void
    {
        try {
            PushFailure::create([
                ...$meta,
                'error_message'     => substr($error, 0, 1000),
                'token_invalidated' => $tokenInvalidated,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record push failure', $meta + ['recording_error' => $e->getMessage()]);
        }
    }
}
