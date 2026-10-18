<?php

namespace Tests\Unit;

use App\Services\YooKassaService;
use Tests\TestCase;

/**
 * ЮKassa SDK типизирует login строго как BaseClient::$login (?int), а у нас
 * это строка из shops.yookassa_shop_id. Обычный пробел при копировании
 * значения в настройках ломал присвоение TypeError'ом внутри самой
 * библиотеки — PaymentController ловит его и отвечает понятным сообщением,
 * но клиент (мобильное приложение/виджет) эту ошибку тихо проглатывает:
 * заказ оформляется, оплата просто не предлагается, без единой подсказки
 * (баг найден 2026-10-18 живым разбором: "заказ просто улетает").
 */
class YooKassaServiceShopIdTest extends TestCase
{
    /** @return int|null значение BaseClient::$login после конструктора */
    private function loginOf(YooKassaService $service): ?int
    {
        $clientProp = new \ReflectionProperty($service, 'client');
        $clientProp->setAccessible(true);
        $client = $clientProp->getValue($service);

        $loginProp = new \ReflectionProperty($client, 'login');
        $loginProp->setAccessible(true);

        return $loginProp->getValue($client);
    }

    public function test_plain_numeric_shop_id_is_accepted(): void
    {
        $service = new YooKassaService('1499516', 'secret');

        $this->assertSame(1499516, $this->loginOf($service));
    }

    /**
     * Именно этот случай ронял setAuth() с TypeError — copy-paste из поля
     * настроек часто тащит за собой пробел по краям.
     */
    public function test_shop_id_with_surrounding_whitespace_is_trimmed(): void
    {
        $service = new YooKassaService(" 1499516\n", 'secret');

        $this->assertSame(1499516, $this->loginOf($service));
    }

    public function test_non_numeric_shop_id_throws_a_clear_error_instead_of_silently_becoming_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ЮKassa shop_id должен быть числом');

        new YooKassaService('abc123', 'secret');
    }

    public function test_null_shop_id_falls_back_without_error(): void
    {
        // Явно обнуляем конфиг-фолбэк — иначе тест зависел бы от того,
        // задан ли платформенный YOOKASSA_SHOP_ID в .env этого окружения.
        config(['services.yookassa.shop_id' => null]);

        $service = new YooKassaService(null, 'secret');

        $this->assertNull($this->loginOf($service));
    }
}
