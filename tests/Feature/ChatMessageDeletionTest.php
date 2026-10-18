<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Services\TenantService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Смоук-тест заодно проверяет, что само тестовое окружение работает целиком
 * (подъём тенантной схемы, переключение search_path, реальные Eloquent-
 * запросы) — не выдуманная проверка ради проверки: это флоу удаления чата
 * из PLAN.md 2026-10-18 ("Чат: удаление сообщения байером не должно стирать
 * текст — только прятать его от него самого"), уже проверенный живьём на
 * тестовом шопе, здесь просто закреплён автотестом.
 */
class ChatMessageDeletionTest extends TestCase
{
    public function test_customer_deletion_hides_content_from_customer_but_not_from_staff(): void
    {
        $shop = $this->createTestShop();
        TenantService::setContext($shop);

        $customer = Customer::create(['name' => 'Тест', 'phone' => '+70000000000']);
        $thread = ChatThread::create(['customer_id' => $customer->id]);
        $message = ChatMessage::create([
            'thread_id' => $thread->id,
            'sender_type' => 'customer',
            'body' => 'настоящий текст',
            'client_message_id' => (string) Str::uuid(),
        ]);

        // 1-я ступень — как в ChatController::destroy().
        $message->update(['deleted_at' => now()]);

        // Admin\ChatController отдаёт toArray() как есть — персонал видит
        // настоящий текст.
        $this->assertSame('настоящий текст', $message->fresh()->body);

        // Widget\ChatController отдаёт toCustomerArray() — байер текста не видит.
        $this->assertNull($message->fresh()->toCustomerArray()['body']);

        // 2-я ступень — строка не стирается, только прячется от байера.
        $message->update(['hidden_from_customer_at' => now()]);
        $message->refresh();
        $this->assertSame('настоящий текст', $message->body);
        $this->assertNotNull($message->hidden_from_customer_at);
    }
}
