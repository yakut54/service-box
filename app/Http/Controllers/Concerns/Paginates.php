<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Общий формат ответа для списков с пагинацией (Заказы, Клиенты — см.
 * UiPagination.vue на фронте, который ждёт ровно эту форму meta).
 * Пагинация только по явному запросу (?page=...) — без него метод
 * отдаёт как раньше, полным списком: аналитика (AnalyticsView.vue)
 * считает топы/графики на клиенте по всей истории заказов/клиентов,
 * не по одной странице.
 */
trait Paginates
{
    protected function paginatedResponse(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }
}
