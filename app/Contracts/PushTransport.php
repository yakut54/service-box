<?php

namespace App\Contracts;

use App\Support\PushMessage;
use App\Support\PushSendResult;

/**
 * Один транспорт доставки push. Сейчас единственная реализация — FirebaseService
 * (FCM). Когда появится статистика недоставки на устройствах без сервисов Google,
 * рядом встанет RuStore Universal Push / HMS — вызывающий код (оркестратор) не
 * меняется, меняется только привязка в AppServiceProvider.
 */
interface PushTransport
{
    /** Отправить один push на один токен. Не бросает исключений — см. PushSendResult. */
    public function send(string $token, PushMessage $message): PushSendResult;
}
