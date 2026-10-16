<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «Сколько нового с прошлого визита на вкладку» — общая механика за
 * бейджами Отзывов / Несработавших писем / Журнала изменений: курсор
 * «я (конкретный пользователь) последний раз смотрел это в момент T»,
 * сравнение created_at с ним, сдвиг курсора на now().
 *
 * Курсор — per-user (public.user_seen_cursors: user_id, shop_id,
 * cursor_key), НЕ shops.<колонка> как было раньше. Баг, найденный живьём
 * 2026-10-16: Юлия просмотрела Журнал изменений, её markSeen() сдвинул
 * общий на весь магазин shops.product_changes_last_seen_at — когда следом
 * зашёл Ховар (владелец), бейдж оказался пустым, хотя лично он ничего не
 * видел. У каждого сотрудника (и владельца) должны быть свои непрочитанные,
 * не общие на магазин.
 *
 * Сам запрос «что считать» — на вызывающей стороне (своя модель, своя
 * CategoryAccess-область, свои доп. условия вроде is_published=false) —
 * трейт унифицирует только работу с курсором, не бизнес-логику фичи.
 */
trait TracksSeenCursor
{
    protected function seenAt(Request $request, string $cursorKey): ?Carbon
    {
        $value = DB::table('user_seen_cursors')
            ->where('user_id', $request->user()->id)
            ->where('shop_id', $request->attributes->get('shop')->id)
            ->where('cursor_key', $cursorKey)
            ->value('seen_at');

        return $value ? Carbon::parse($value) : null;
    }

    protected function pendingCountSince(Request $request, string $cursorKey, Builder $query): int
    {
        $since = $this->seenAt($request, $cursorKey);
        return $query->when($since, fn ($q) => $q->where('created_at', '>', $since))->count();
    }

    protected function markSeenNow(Request $request, string $cursorKey): void
    {
        DB::table('user_seen_cursors')->upsert(
            [[
                'user_id'    => $request->user()->id,
                'shop_id'    => $request->attributes->get('shop')->id,
                'cursor_key' => $cursorKey,
                'seen_at'    => now(),
            ]],
            ['user_id', 'shop_id', 'cursor_key'],
            ['seen_at']
        );
    }
}
