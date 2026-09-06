<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Размерная сетка уровня магазина — одна «Женские платья» переиспользуется
 * многими товарами (см. products.size_chart_id). Пресеты RU/EU/US живут во
 * фронте (SizeChartPicker) — здесь только произвольная редактируемая таблица.
 *
 * columns: ["Размер", "Обхват груди, см", ...] — заголовки.
 * rows:    [["S", "82-86", ...], ["M", ...]]   — строки, все значения строками.
 */
class SizeChart extends Model
{
    use HasUuids;

    protected $table = 'size_charts';

    protected $fillable = [
        'kind',
        'name',
        'columns',
        'rows',
    ];

    protected $casts = [
        'columns'    => 'array',
        'rows'       => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function products()
    {
        return $this->hasMany(Product::class, 'size_chart_id');
    }
}
