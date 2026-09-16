<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * «Брошенная корзина» — одна строка на покупателя. PK — id самого покупателя
 * (не сгенерированный), поэтому не HasUuids. Хранит только количество единиц
 * в корзине (CartState.itemCount на мобилке — сумма quantity, не число
 * строк) и `updated_at` как часы заброшенности; состав корзины на сервер не
 * попадает вообще. См. App\Console\Commands\SendCartReminders.
 */
class CartActivity extends Model
{
    protected $table = 'cart_activity';

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'customer_id',
        'items_count',
        'first_reminded_at',
        'second_reminded_at',
    ];

    protected $casts = [
        'items_count'        => 'integer',
        'first_reminded_at'  => 'datetime',
        'second_reminded_at' => 'datetime',
    ];
}
