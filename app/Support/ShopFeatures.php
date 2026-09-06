<?php

namespace App\Support;

/**
 * Единый каталог фич и рубильников магазина. Одно место вместо разбросанных по
 * коду строковых ключей. Суперадмин переключает их в мастер-админке
 * (Superadmin\SuperadminShopController::features / toggleFeature).
 *
 * Два типа записи:
 *   'feature' — строка в public.shop_features. Нет строки = выключено
 *               (Shop::hasFeature). Так живут платные функции «Флота Шоперов».
 *   'flag'    — булева колонка в public.shops. Для рубильников, которые по
 *               умолчанию ВКЛючены — отсутствие строки для них не годится.
 */
final class ShopFeatures
{
    /** Псевдо-ключ для колонки shops.customer_push_enabled. */
    public const CUSTOMER_PUSH = 'customer_push';

    /**
     * @return array<string, array{label:string, description:string, type:string, column?:string, default:bool}>
     */
    public static function catalog(): array
    {
        return [
            'booking' => [
                'label'       => 'Онлайн-запись',
                'description' => 'Услуги и запись на время в виджете и приложении',
                'type'        => 'feature',
                'default'     => false,
            ],
            'digital_goods' => [
                'label'       => 'Цифровые товары',
                'description' => 'Продажа цифровых товаров: файлы, ключи, доступы',
                'type'        => 'feature',
                'default'     => false,
            ],
            self::CUSTOMER_PUSH => [
                'label'       => 'Push покупателям',
                'description' => 'Транзакционные пуши на телефон (статус заказа, сообщение в чате). Аварийный выключатель на магазин; мессенджеры при этом работают.',
                'type'        => 'flag',
                'column'      => 'customer_push_enabled',
                'default'     => true,
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::catalog());
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }
}
