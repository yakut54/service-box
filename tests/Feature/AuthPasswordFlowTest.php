<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Смена/восстановление пароля — живой вопрос 2026-10-19 ("как Юлии
 * сменить пароль через админку"). Бэкенд (AuthController::changePassword/
 * forgotPassword/resetPassword) уже role-agnostic — работает на уровне
 * User, не shop_staff, ни разу не смотрит на роль. Чинили только фронт
 * (доступ к вкладке/модалке), но тестов на сам флоу не было вообще —
 * этот файл закрывает именно его, явно для всех трёх ролей там, где это
 * осмысленно: не потому что логика разная, а чтобы тест был живым
 * стражем на случай, если кто-то потом добавит сюда ролевую проверку,
 * которой быть не должно.
 */
class AuthPasswordFlowTest extends TestCase
{
    private function ownerOf(Shop $shop): User
    {
        return $shop->user;
    }

    // ── forgotPassword ──────────────────────────────────────────────────

    public static function roleProvider(): array
    {
        return [
            'owner' => ['owner'],
            'admin' => ['admin'],
            'collector' => ['collector'],
        ];
    }

    #[DataProvider('roleProvider')]
    public function test_forgot_password_queues_reset_mail_for_any_role(string $role): void
    {
        Mail::fake();
        $shop = $this->createTestShop();
        $user = $role === 'owner' ? $this->ownerOf($shop) : $this->staffMember($shop, 'Тест', $role);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'Письмо со ссылкой для сброса пароля отправлено');

        Mail::assertQueued(ResetPasswordMail::class, fn (ResetPasswordMail $mail) => $mail->userEmail === $user->email);
    }

    /**
     * Анти-энумерация (комментарий в самом контроллере) — несуществующий
     * email отвечает ТЕМ ЖЕ текстом, что и существующий, и письма не шлёт.
     * Без этого теста регрессия (например, кто-то решит "улучшить" текст
     * ошибки на "пользователь не найден") прошла бы незамеченной.
     */
    public function test_forgot_password_responds_identically_for_an_unknown_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody-' . Str::uuid() . '@example.test']);

        $response->assertOk()->assertJsonPath('message', 'Письмо со ссылкой для сброса пароля отправлено');
        Mail::assertNothingQueued();
    }

    /**
     * Текущее поведение — email сверяется БЕЗ нормализации регистра (как и
     * login(), тот же Auth::attempt()/where('email', ...) без lower()).
     * Тест документирует это как есть, не меняю: если понадобится
     * регистро-независимый email, это отдельная задача на весь auth, не
     * точечная правка здесь.
     */
    public function test_forgot_password_email_lookup_is_case_sensitive(): void
    {
        Mail::fake();
        $shop = $this->createTestShop();
        $user = $this->staffMember($shop, 'Юлия', 'admin');

        $this->postJson('/api/auth/forgot-password', ['email' => Str::upper($user->email)])
            ->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_forgot_password_is_throttled_after_five_attempts(): void
    {
        Mail::fake();
        $shop = $this->createTestShop();
        $user = $this->staffMember($shop, 'Егорка', 'collector');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(429);
    }

    // ── resetPassword ────────────────────────────────────────────────────

    #[DataProvider('roleProvider')]
    public function test_reset_password_with_a_valid_token_changes_the_password_for_any_role(string $role): void
    {
        $shop = $this->createTestShop();
        $user = $role === 'owner' ? $this->ownerOf($shop) : $this->staffMember($shop, 'Тест', $role);
        $user->createToken('session'); // живая сессия — должна слететь после сброса

        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_reset_password_with_an_invalid_token_is_rejected(): void
    {
        $shop = $this->createTestShop();
        $user = $this->staffMember($shop, 'Егорка', 'collector');

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ссылка для сброса пароля недействительна или устарела');
    }

    /**
     * Токен реальный и свежий, но выписан для ДРУГОГО пользователя —
     * нельзя сменить чужой пароль, подставив своё письмо-токен под чужой
     * email (или наоборот). Password::reset() сверяет токен именно с тем
     * email, для которого он выписывался, — тест закрепляет это явно.
     */
    public function test_reset_password_token_cannot_be_used_with_a_different_email(): void
    {
        $shop = $this->createTestShop();
        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        $egorka = $this->staffMember($shop, 'Егорка', 'collector');

        $julyaToken = Password::createToken($julia);

        $this->postJson('/api/auth/reset-password', [
            'token' => $julyaToken,
            'email' => $egorka->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422);

        $this->assertFalse(Hash::check('new-password-123', $egorka->fresh()->password));
    }

    /**
     * Повторное использование уже сработавшего токена — Laravel сам
     * удаляет строку в password_reset_tokens после успешного reset(),
     * тест закрепляет, что второй заход с тем же токеном не пройдёт
     * (иначе письмо со ссылкой стало бы бессрочным ключом).
     */
    public function test_reset_password_token_cannot_be_reused(): void
    {
        $shop = $this->createTestShop();
        $user = $this->staffMember($shop, 'Юлия', 'admin');
        $token = Password::createToken($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'first-new-password',
            'password_confirmation' => 'first-new-password',
        ];
        $this->postJson('/api/auth/reset-password', $payload)->assertOk();

        $this->postJson('/api/auth/reset-password', array_merge($payload, [
            'password' => 'second-new-password',
            'password_confirmation' => 'second-new-password',
        ]))->assertStatus(422);

        $this->assertTrue(Hash::check('first-new-password', $user->fresh()->password));
    }

    public function test_reset_password_rejects_mismatched_confirmation(): void
    {
        $shop = $this->createTestShop();
        $user = $this->staffMember($shop, 'Егорка', 'collector');
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'does-not-match',
        ])->assertStatus(422);
    }

    // ── changePassword ───────────────────────────────────────────────────

    #[DataProvider('roleProvider')]
    public function test_change_password_with_correct_current_password_works_for_any_role(string $role): void
    {
        $shop = $this->createTestShop();
        $user = $role === 'owner' ? $this->ownerOf($shop) : $this->staffMember($shop, 'Тест', $role);
        $user->update(['password' => bcrypt('old-password')]);
        $user->createToken('other-device'); // должна слететь вместе с текущей

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/change-password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Пароль успешно изменён');

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $shop = $this->createTestShop();
        $user = $this->staffMember($shop, 'Юлия', 'admin');
        $user->update(['password' => bcrypt('old-password')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/change-password', [
                'current_password' => 'totally-wrong',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Текущий пароль неверен');

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_change_password_rejects_a_short_new_password(): void
    {
        $shop = $this->createTestShop();
        $user = $this->staffMember($shop, 'Егорка', 'collector');
        $user->update(['password' => bcrypt('old-password')]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/change-password', [
                'current_password' => 'old-password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422);
    }

    public function test_change_password_requires_authentication(): void
    {
        $this->postJson('/api/auth/change-password', [
            'current_password' => 'whatever',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(401);
    }
}
