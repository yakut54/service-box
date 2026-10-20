<?php

namespace Tests\Feature\ExceptionHandling;

use App\Models\Shop;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-20 — запрос несуществующего заказа по прямой ссылке
 * отдавал сырой текст "No query results for model [App\Models\Order]
 * <uuid>" прямо в JSON (не APP_DEBUG — Laravel считает это сообщение
 * "безопасным" и не трогает его). См. bootstrap/app.php::withExceptions().
 */
class ApiExceptionMessagesTest extends TestCase
{
    public function test_missing_order_returns_a_clean_russian_message_not_the_raw_exception(): void
    {
        $shop = $this->createTestShop();
        $missingId = (string) Str::uuid();

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson("/api/admin/orders/{$missingId}");

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Запись не найдена']);
        $this->assertStringNotContainsString('No query results', $response->getContent());
        $this->assertStringNotContainsString('App\\Models\\Order', $response->getContent());
    }

    public function test_unmatched_route_404_is_not_touched_by_the_new_handler(): void
    {
        // Новый обработчик ловит строго ModelNotFoundException — несуществующий
        // МАРШРУТ (NotFoundHttpException от самого роутера, не от нас) не
        // должен внезапно получить текст "Запись не найдена", который не
        // имеет к этому отношения.
        $response = $this->getJson('/api/admin/this-route-does-not-exist');

        $response->assertStatus(404);
        $this->assertNotSame('Запись не найдена', $response->json('message'));
    }

    public function test_missing_auth_token_returns_a_russian_message(): void
    {
        $response = $this->getJson('/api/admin/orders');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Не авторизован']);
    }
}
