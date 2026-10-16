<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Сколько нового с прошлого визита на вкладку» — общая механика за
 * бейджами Отзывов / Несработавших писем / Журнала изменений: курсор
 * shops.<column>_last_seen_at, сравнение created_at с ним, сдвиг курсора на
 * now(). Раньше каждый контроллер писал это руками заново (одинаковый
 * when()+count() и update([...])).
 *
 * Сам запрос «что считать» — на вызывающей стороне (своя модель, своя
 * CategoryAccess-область, свои доп. условия вроде is_published=false) —
 * трейт унифицирует только работу с курсором, не бизнес-логику фичи.
 */
trait TracksSeenCursor
{
    protected function pendingCountSince(Shop $shop, string $column, Builder $query): int
    {
        $since = $shop->{$column};
        return $query->when($since, fn ($q) => $q->where('created_at', '>', $since))->count();
    }

    protected function markSeenNow(Shop $shop, string $column): void
    {
        $shop->update([$column => now()]);
    }
}
