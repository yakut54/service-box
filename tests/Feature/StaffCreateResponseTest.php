<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-19 ("Мякиш" задвоился в списке Команды) —
 * побочная находка на пути к главному фронтовому фиксу: ответ на создание
 * сотрудника отдавал только "сырой" admin_id, без admin_name, хотя
 * карточка в вебе рисует "Администратор: X" именно по имени. До первого
 * фонового обновления списка (WS/опрос) это давало короткое неверное
 * "Подчиняется владельцу" для сборщика, которого явно привязали к админу.
 */
class StaffCreateResponseTest extends TestCase
{
    public function test_create_response_includes_the_admin_name_when_owner_assigns_an_admin(): void
    {
        Mail::fake();
        $shop = $this->createTestShop();
        $julia = $this->staffMember($shop, 'Юлия', 'admin');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/staff', [
                'name' => 'Мякиш',
                'email' => 'myakish-' . uniqid() . '@example.test',
                'role' => 'collector',
                'admin_id' => \App\Models\ShopStaff::where('user_id', $julia->id)->value('id'),
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.admin_name', 'Юлия');
    }

    public function test_create_response_has_a_null_admin_name_when_no_admin_is_assigned(): void
    {
        Mail::fake();
        $shop = $this->createTestShop();

        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/staff', [
                'name' => 'Мякиш',
                'email' => 'myakish-' . uniqid() . '@example.test',
                'role' => 'collector',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.admin_name', null);
    }

    public function test_create_response_includes_the_admin_name_when_an_admin_invites_their_own_collector(): void
    {
        Mail::fake();
        $shop = $this->createTestShop();
        $julia = $this->staffMember($shop, 'Юлия', 'admin');

        $response = $this->actingAs($julia, 'sanctum')
            ->postJson('/api/admin/staff', [
                'name' => 'Мякиш',
                'email' => 'myakish-' . uniqid() . '@example.test',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.admin_name', 'Юлия');
    }

    public function test_resend_response_includes_the_admin_name_too(): void
    {
        Mail::fake();
        $shop = $this->createTestShop();
        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        $email = 'myakish-' . uniqid() . '@example.test';
        $adminStaffId = \App\Models\ShopStaff::where('user_id', $julia->id)->value('id');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/staff', [
                'name' => 'Мякиш',
                'email' => $email,
                'role' => 'collector',
                'admin_id' => $adminStaffId,
            ])
            ->assertStatus(201);

        // Повторная отправка на тот же email — "уже является сотрудником"
        // ветка у owner'а не применяется (она только для isActingAdmin),
        // владелец просто переотправляет существующее pending-приглашение.
        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/staff', [
                'name' => 'Мякиш',
                'email' => $email,
                'role' => 'collector',
                'admin_id' => $adminStaffId,
            ])
            ->assertOk();

        $response->assertJsonPath('data.admin_name', 'Юлия');
    }
}
