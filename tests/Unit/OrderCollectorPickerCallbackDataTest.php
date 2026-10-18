<?php

namespace Tests\Unit;

use App\Services\MaxService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * callback_data у Telegram ограничен 64 байтами — этот лимит уже один раз
 * тихо сломал пикер сотрудников (баг 2026-10-18, найден по скриншоту
 * пользователя): "order:assign:" + UUID заказа + UUID сотрудника ~86 байт,
 * Telegram отвечал 400 на editMessageReplyMarkup, кнопки оставались
 * старыми, а лог молчал (Http не бросает исключение на 4xx). Тест ловит
 * регресс по форме кнопки, а не угадыванием длины вручную — если кто-то
 * снова впишет userId прямо в callback_data, тест упадёт.
 */
class OrderCollectorPickerCallbackDataTest extends TestCase
{
    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function longUuidOptions(int $count): array
    {
        $options = [];
        for ($i = 0; $i < $count; $i++) {
            $options[] = ['id' => (string) Str::uuid(), 'name' => 'Сотрудник номер ' . $i];
        }

        return $options;
    }

    public function test_telegram_picker_callback_data_fits_the_64_byte_limit(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $orderId = (string) Str::uuid();
        TelegramService::showOrderCollectorPicker(123456, 111, $orderId, $this->longUuidOptions(4));

        Http::assertSent(function ($request) {
            $markup = json_decode($request['reply_markup'], true);

            foreach ($markup['inline_keyboard'] as $row) {
                foreach ($row as $button) {
                    $this->assertLessThanOrEqual(64, strlen($button['callback_data']));
                }
            }

            return true;
        });
    }

    public function test_max_picker_callback_data_is_short(): void
    {
        config(['services.max.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $orderId = (string) Str::uuid();
        // showOrderCollectorPicker достаёт mid из того же кеша, что кладёт
        // notifyNewOrder() — без него выходит раньше, HTTP не уйдёт вовсе.
        Cache::put("max_mid:order:{$orderId}", ['user_id' => 123, 'mid' => 'mid-1'], now()->addMinutes(5));

        MaxService::showOrderCollectorPicker($orderId, $this->longUuidOptions(4));

        Http::assertSent(function ($request) {
            $buttonRows = $request['attachments'][0]['payload']['buttons'];

            foreach ($buttonRows as $row) {
                foreach ($row as $button) {
                    $this->assertLessThanOrEqual(64, strlen($button['payload']));
                }
            }

            return true;
        });
    }
}
