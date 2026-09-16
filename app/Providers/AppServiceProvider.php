<?php

namespace App\Providers;

use App\Events\NavCountsUpdated;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ShopStaff;
use App\Services\TenantService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Единственная реализация транспорта push. Позже рядом встанет
        // RuStore Universal Push / HMS — тогда здесь появится выбор по платформе.
        $this->app->bind(\App\Contracts\PushTransport::class, \App\Services\FirebaseService::class);
    }

    public function boot(): void
    {
        $this->registerNavCountsBroadcasts();

        RateLimiter::for('telegram-webhook', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('yookassa-webhook', function (Request $request) {
            $shopId = $request->input('object.metadata.shop_id') ?? $request->ip();
            return Limit::perMinute(30)->by("yookassa:{$shopId}");
        });

        // Дефолтный ответ Laravel при превышении лимита — захардкоженный
        // английский текст "Too Many Attempts.", не переведённый через lang
        // файлы (баг найден 2026-08-23 — шоперу-владельцу показывало текст
        // на английском). Переопределяем на всех чатовых лимитерах ниже.
        $tooManyAttempts = fn (Request $request, array $headers) => response()->json(
            ['message' => 'Слишком много запросов, подождите немного'],
            429,
            $headers
        );

        // Чат покупателя: ключ — токен сессии (X-Phone-Session), не IP.
        // Покупатель авторизован кастомным заголовком, а не через Sanctum-guard,
        // поэтому $request->user() пуст и голый throttle:N,1 откатился бы на IP —
        // на carrier-grade NAT мобильных операторов это значило бы, что один
        // активный чат исчерпывает лимит на всех соседей по тому же оператору
        // (см. PLAN-CHAT.md §3.3). IP — только аварийный fallback, если заголовка
        // почему-то нет вообще (такой запрос всё равно отклонит
        // VerifyPhoneSession раньше, чем дойдёт до контроллера).
        RateLimiter::for('chat-read', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(60)->by($request->header('X-Phone-Session') ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-write', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(20)->by($request->header('X-Phone-Session') ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-image', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(10)->by($request->header('X-Phone-Session') ?? $request->ip())->response($tooManyAttempts);
        });

        // Активность корзины (брошенная корзина) — тот же приём, что у чата
        // покупателя выше: ключ по X-Phone-Session, не по IP.
        RateLimiter::for('cart-activity', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(30)->by($request->header('X-Phone-Session') ?? $request->ip())->response($tooManyAttempts);
        });

        // Чат в админке — именованные лимитеры вместо голого throttle:N,1.
        // БАГ (найден 2026-08-23 живьём): голый throttle:N,1 для
        // авторизованного пользователя строит ключ ИСКЛЮЧИТЕЛЬНО из
        // sha1(user_id), без учёта роута — то есть все 8 роутов чата
        // админки (index/messages/poll/read/block/image, каждый со своим
        // числом попыток) делили ОДИН общий счётчик на владельца магазина.
        // Экран диалога опрашивает messages каждые 4с + список тредов
        // каждые 10с + бейдж каждые 15с — это легко больше 20 запросов в
        // минуту само по себе, и отправка сообщения (лимит 20) утыкалась в
        // уже почти исчерпанный чужими GET'ами счётчик. Именованные
        // лимитеры с разными именами гарантированно не пересекаются.
        RateLimiter::for('chat-admin-read', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(180)->by($request->user()?->id ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-admin-write', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-admin-moderate', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-admin-image', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(10)->by($request->user()?->id ?? $request->ip())->response($tooManyAttempts);
        });

        // Суперадмин создаёт админа с точкой — дорогая операция (DROP/CREATE
        // SCHEMA + ~30 таблиц), именованный лимитер вместо голого throttle:N,1
        // по той же причине, что у чата (общий счётчик без учёта роута).
        RateLimiter::for('superadmin-write', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(5)->by($request->user()?->id ?? $request->ip())->response($tooManyAttempts);
        });

        // Вход по паролю — раньше голый throttle:10,1 считал только по IP:
        // атакующий с ротацией IP мог перебирать пароль одного конкретного
        // аккаунта без ограничений. Тот же приём, что уже применён к
        // SMS-коду (WidgetPhoneVerificationController) — лимит и на IP, и
        // отдельно на сам аккаунт (аудит безопасности 2026-09-15).
        RateLimiter::for('login', function (Request $request) use ($tooManyAttempts) {
            $email = mb_strtolower(trim((string) $request->input('email')));
            return [
                Limit::perMinute(10)->by('login-ip:'.$request->ip())->response($tooManyAttempts),
                Limit::perMinute(5)->by('login-email:'.$email)->response($tooManyAttempts),
            ];
        });
    }

    /**
     * Серые цифры «всего» в сайдборе (NavCountsController) — реалтайм по
     * созданию/удалению Товара/Категории/Скидки/Сотрудника/Клиента. Один
     * обсервер на 5 моделей вместо копирования NavCountsUpdated::dispatch()
     * в 8 разных контроллеров (ProductController/CategoryController/
     * DiscountController/StaffController/CustomerController) — первый и
     * единственный обсервер в проекте, других пока нет.
     *
     * Product/Category/Discount/Customer — тенантные таблицы (создаются
     * per-shop в create_shop_schema(), см. database/schema/pgsql-schema.sql),
     * поэтому shop_id берём из TenantService::getCurrentShopId() — в момент
     * срабатывания события контекст уже корректно выставлен запросом,
     * который создал/удалил запись. ShopStaff — публичная таблица со своей
     * колонкой shop_id, там TenantService не нужен.
     *
     * Массовые delete (whereIn(...)->delete()) Eloquent-события модели не
     * бьют — см. явный dispatch в CategoryController::destroy() для
     * action=delete, этот обсервер его не покрывает.
     */
    private function registerNavCountsBroadcasts(): void
    {
        foreach ([Product::class, Category::class, Discount::class, Customer::class] as $model) {
            $model::created(fn (Model $m) => $this->broadcastNavCounts(TenantService::getCurrentShopId()));
            $model::deleted(fn (Model $m) => $this->broadcastNavCounts(TenantService::getCurrentShopId()));
        }

        ShopStaff::created(fn (ShopStaff $m) => $this->broadcastNavCounts($m->shop_id));
        ShopStaff::deleted(fn (ShopStaff $m) => $this->broadcastNavCounts($m->shop_id));
    }

    /**
     * DB::afterCommit() — часть этих create/delete (например, Customer при
     * оформлении заказа, см. Customer::findOrCreateByPhone из
     * OrderController::store) происходит ВНУТРИ DB::transaction(). Событие
     * модели 'created' срабатывает сразу, до коммита — рассылать
     * NavCountsUpdated в этот момент значит слать его раньше, чем строка
     * реально появится в БД. afterCommit() сам разруливает оба случая:
     * если транзакции нет — выполнит сразу, если есть — отложит до коммита.
     */
    private function broadcastNavCounts(?string $shopId): void
    {
        if ($shopId) {
            DB::afterCommit(fn () => NavCountsUpdated::dispatch($shopId));
        }
    }
}
