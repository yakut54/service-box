<?php

namespace App\Support;

/**
 * Итог одной попытки отправки push через транспорт (см. App\Contracts\PushTransport).
 *
 *  - Ok            — доставлено в шлюз (это НЕ гарантия показа на устройстве, см.
 *                    push-notifications-research.pdf §1.3);
 *  - InvalidToken  — токен больше не зарегистрирован (FCM 404 / UNREGISTERED).
 *                    Вызывающий обязан удалить запись токена — ретрай бессмыслен;
 *  - TransientError — временная ошибка (сеть, 5xx шлюза, канал не настроен).
 *                    Токен оставляем, можно повторить позже.
 */
enum PushSendResult
{
    case Ok;
    case InvalidToken;
    case TransientError;
}
