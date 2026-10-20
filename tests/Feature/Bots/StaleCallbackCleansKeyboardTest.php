<?php

namespace Tests\Feature\Bots;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-20 — клик по кнопке заказа, который уже не
 * существует (удалён), отвечает "Заказ не найден", но раньше не снимал
 * клавиатуру с того же сообщения — она оставалась кликабельной навсегда.
 * См. TelegramController/MaxController::handleOrderAction/Assign/Refresh.
 */
class StaleCallbackCleansKeyboardTest extends TestCase
{
    public function test_telegram_confirm_on_a_missing_order_removes_the_keyboard(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $shop = $this->createTestShop(['telegram_chat_id' => random_int(100_000_000, 999_999_999), 'telegram_bot_connected' => true]);
        $missingOrderId = (string) Str::uuid();

        $this->postJson('/api/webhook/telegram', [
            'callback_query' => [
                'id' => 'cb-' . Str::uuid(),
                'data' => "order:confirm:{$missingOrderId}",
                'message' => [
                    'chat' => ['id' => $shop->telegram_chat_id],
                    'message_id' => 777,
                ],
            ],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageReplyMarkup')
            && $request['message_id'] === 777);
    }

    public function test_max_confirm_on_a_missing_order_removes_the_keyboard(): void
    {
        // VerifyMaxWebhook требует непустой секрет, совпадающий с {secret}
        // в пути — без этого 503 "Webhook не настроен" (см. MaxOrderAssignTest).
        config(['services.max.webhook_secret' => 'test-secret']);
        config(['services.max.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $shop = $this->createTestShop(['max_chat_id' => random_int(100_000_000, 999_999_999), 'max_bot_connected' => true]);
        $missingOrderId = (string) Str::uuid();

        $this->postJson('/api/webhook/max/test-secret', [
            'update_type' => 'message_callback',
            'callback' => [
                'callback_id' => 'cb-' . Str::uuid(),
                'payload' => "order:confirm:{$missingOrderId}",
                'user' => ['user_id' => $shop->max_chat_id],
                'message' => ['body' => ['mid' => 'mid-777']],
            ],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages')
            && str_contains($request->url(), 'mid-777'));
    }
}
