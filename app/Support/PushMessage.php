<?php

namespace App\Support;

/**
 * Транспортно-независимое описание одного push. Собирается на сервере (текст уже
 * на языке пользователя, суммы через App\Support\Money, время в таймзоне
 * магазина). В payload — только идентификаторы: push это сигнал, а не носитель
 * данных (см. push-notifications-research.pdf).
 */
final class PushMessage
{
    /**
     * @param array<string,string> $data  полезная нагрузка для приложения (строки!)
     * @param string|null $channelId      Android notification channel (orders/delivery/chat/promo/...)
     * @param string|null $collapseKey    FCM collapse key: если устройство офлайн,
     *                                    доедет только последнее сообщение с этим ключом
     * @param string $priority            'high' (будит в Doze, для видимого пользователю) | 'normal'
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $channelId = null,
        public readonly ?string $collapseKey = null,
        public readonly string $priority = 'high',
    ) {}
}
