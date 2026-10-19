<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Активен" в разделе Команда (StaffController::index) — свой батч-запрос
 * по тем же правилам, что User::isOnline() (см. его докблок), не через
 * вызов isOnline() по одному (был бы N+1). Живой разбор 2026-10-19:
 * "Егорка не онлайн, а показывает онлайн" — тот же баг, тот же фикс,
 * нужен свой тест, раз запрос отдельный.
 */
class StaffOnlineStatusTest extends TestCase
{
    public function test_staff_with_a_live_recently_used_token_is_active(): void
    {
        $shop = $this->createTestShop();
        $egorka = $this->staffMember($shop, 'Егорка', 'collector');
        $egorka->createToken('session');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson('/api/admin/staff')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('user.id', $egorka->id);
        $this->assertTrue($row['is_online']);
    }

    /**
     * Сам баг: токен реально есть, но не использовался (и создан) больше
     * 5 минут назад — закрыл вкладку/сел телефон, "Выйти" не нажимал.
     */
    public function test_staff_with_a_stale_unused_token_is_not_active(): void
    {
        $shop = $this->createTestShop();
        $egorka = $this->staffMember($shop, 'Егорка', 'collector');
        $token = $egorka->createToken('session');
        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update([
                'created_at' => now()->subMinutes(10),
                'last_used_at' => now()->subMinutes(10),
            ]);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson('/api/admin/staff')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('user.id', $egorka->id);
        $this->assertFalse($row['is_online']);
    }

    public function test_staff_without_any_token_is_not_active(): void
    {
        $shop = $this->createTestShop();
        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        // Нет createToken() — аккаунт существует, но нигде не залогинен.

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson('/api/admin/staff')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('user.id', $julia->id);
        $this->assertFalse($row['is_online']);
    }

    /**
     * Регрессия к самому фиксу — см. User::isOnline() докблок: только что
     * созданный токен ещё не имеет last_used_at (Sanctum проставляет его
     * не при создании, а при первом запросе ЭТИМ токеном).
     */
    public function test_staff_with_a_brand_new_token_and_no_last_used_at_yet_is_active(): void
    {
        $shop = $this->createTestShop();
        $egorka = $this->staffMember($shop, 'Егорка', 'collector');
        $egorka->createToken('session');
        $this->assertNull(
            DB::table('personal_access_tokens')->where('tokenable_id', $egorka->id)->value('last_used_at')
        );

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson('/api/admin/staff')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('user.id', $egorka->id);
        $this->assertTrue($row['is_online']);
    }

    /**
     * Несколько сотрудников в одном ответе — батч-запрос (whereIn) должен
     * верно различать "давно неактивен" и "активен только что" в одном
     * вызове, не просто случайно совпасть на одном пользователе.
     */
    public function test_batch_query_distinguishes_active_and_stale_among_several_staff(): void
    {
        $shop = $this->createTestShop();
        $egorka = $this->staffMember($shop, 'Егорка', 'collector');
        $julia = $this->staffMember($shop, 'Юлия', 'admin');

        $egorkaToken = $egorka->createToken('session');
        DB::table('personal_access_tokens')
            ->where('id', $egorkaToken->accessToken->id)
            ->update(['created_at' => now()->subMinutes(10), 'last_used_at' => now()->subMinutes(10)]);
        $julia->createToken('session');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson('/api/admin/staff')
            ->assertOk();

        $rows = collect($response->json('data'));
        $this->assertFalse($rows->firstWhere('user.id', $egorka->id)['is_online']);
        $this->assertTrue($rows->firstWhere('user.id', $julia->id)['is_online']);
    }
}
