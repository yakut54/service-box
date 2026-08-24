<?php

use App\Models\ChatThread;
use App\Models\ShopStaff;
use App\Services\TenantService;
use Illuminate\Support\Facades\Broadcast;

/**
 * Приватный канал на тред чата — слушают и сотрудник магазина (через эту
 * стандартную Sanctum-авторизацию), и покупатель (через отдельный
 * кастомный auth-эндпоинт для X-Phone-Session, см. ChatController::broadcastAuth
 * — обычный /broadcasting/auth тут не подходит, т.к. покупатель не
 * Sanctum-пользователь, см. PLAN-CHAT.md §4).
 *
 * $shopApiKey передаётся клиентом как часть имени канала, потому что канал
 * авторизуется до того, как известен тенантный контекст — обычный
 * middleware 'tenant' тут не участвует (это не HTTP-роут с тем же
 * жизненным циклом), поэтому контекст выставляем вручную внутри колбэка.
 */
Broadcast::channel('chat.thread.{shopApiKey}.{threadId}', function ($user, string $shopApiKey, string $threadId) {
    try {
        $shop = TenantService::setContextByApiKey($shopApiKey);
    } catch (\Exception) {
        return false;
    }

    $thread = ChatThread::find($threadId);
    if (!$thread) {
        TenantService::resetContext();
        return false;
    }

    $staff = ShopStaff::where('shop_id', $shop->id)
        ->where('user_id', $user->id)
        ->whereIn('role', ['owner', 'admin'])
        ->exists();

    $allowed = $staff || $user->id === $shop->user_id;
    TenantService::resetContext();

    return $allowed;
});

/**
 * Личный канал пользователя — уведомление «вошли с другого устройства»
 * (см. AuthController::login, UserSessionSuperseded). Публичной информации
 * не несёт, поэтому единственная проверка — что слушает именно тот, о чьей
 * сессии речь, не кто-то другой.
 */
Broadcast::channel('user.{userId}', function ($user, string $userId) {
    return (string) $user->id === $userId;
});
