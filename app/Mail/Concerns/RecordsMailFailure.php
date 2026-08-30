<?php

namespace App\Mail\Concerns;

use App\Services\MailFailureRecorder;

/**
 * Финальный хук очереди: Illuminate\Mail\SendQueuedMailable::failed() вызывает
 * $mailable->failed($e) автоматически, если у Mailable-класса есть метод failed() —
 * срабатывает после того как все попытки (--tries=3 в queue:work) исчерпаны.
 * Используется всеми 4 Mailable-классами письма о заказе/записи вместо копирования
 * одинакового тела метода в каждый.
 */
trait RecordsMailFailure
{
    public readonly array $failureMeta;

    public function failed(\Throwable $e): void
    {
        MailFailureRecorder::record($this->failureMeta, $e->getMessage());
    }
}
