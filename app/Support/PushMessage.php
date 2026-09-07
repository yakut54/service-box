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
     * @param string|null $androidSound   имя ресурса в android/.../res/raw без расширения
     *                                    (напр. 'chat_notify'); null — системный звук канала
     * @param string|null $androidTag     тег уведомления Android: новый push с тем же
     *                                    тегом заменяет предыдущий в шторке, а приложение
     *                                    может снять его по этому тегу (удаление сообщения)
     * @param bool $dataOnly              не прикреплять notification-блок — «тихий» служебный
     *                                    push, который приложение обрабатывает само (напр.
     *                                    сигнал «сообщение удалено, убери из шторки»)
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $channelId = null,
        public readonly ?string $collapseKey = null,
        public readonly string $priority = 'high',
        public readonly ?string $androidSound = null,
        public readonly ?string $androidTag = null,
        public readonly bool $dataOnly = false,
    ) {}
}
