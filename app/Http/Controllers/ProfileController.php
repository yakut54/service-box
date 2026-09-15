<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPushToken;
use App\Services\ImageCompressionService;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Профиль байера в мобильном приложении. Доступ — только по долгой сессии
 * (см. VerifyPhoneSession), как и адреса (CustomerAddressController).
 */
class ProfileController extends Controller
{
    /**
     * GET /api/widget/profile
     */
    public function show(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        return response()->json(['data' => $this->present($customer)]);
    }

    /**
     * PUT /api/widget/profile
     */
    public function update(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
        ], [
            'name.required' => 'Укажите имя',
            'name.min' => 'Минимум 2 символа',
        ]);

        $customer->update(['name' => $data['name']]);

        return response()->json(['data' => $this->present($customer)]);
    }

    /**
     * POST /api/widget/profile/avatar
     *
     * Без svg (`image` — ярлык на mimes:jpg,jpeg,png,bmp,gif,svg,webp
     * целиком, пропускал svg с <script> — та же дыра, что была найдена и
     * закрыта в чате, здесь оставалась открытой до аудита 2026-09-15).
     * Всегда декодируется и перекодируется через GD — то, что не
     * распознаётся как настоящий JPEG/PNG/WebP, до диска не долетает.
     * Сжатие на мобилке перед отправкой уже есть; здесь — гарантия на
     * случай прямого вызова API мимо приложения (см. ImageCompressionService).
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $request->validate([
            'avatar' => 'required|file|mimes:jpeg,png,webp|max:15360',
        ], [
            'avatar.mimes' => 'Файл должен быть изображением (JPEG, PNG или WebP)',
            'avatar.max' => 'Максимальный размер файла — 15 МБ',
        ]);

        $oldUrl = $customer->avatar_url;

        $compressed = ImageCompressionService::compressToWebp($request->file('avatar'));
        $filename = Str::uuid().'.webp';
        Storage::disk('public')->put('avatars/'.$filename, $compressed);

        $customer->update(['avatar_url' => Storage::disk('public')->url('avatars/'.$filename)]);

        // Старый файл удаляем только после того, как новый успешно сохранён —
        // чтобы при сбое загрузки не остаться без обеих картинок.
        StorageService::deleteByUrl($oldUrl);

        return response()->json(['data' => $this->present($customer)]);
    }

    /**
     * DELETE /api/widget/profile/avatar
     */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        StorageService::deleteByUrl($customer->avatar_url);
        $customer->update(['avatar_url' => null]);

        return response()->json(['data' => $this->present($customer)]);
    }

    /**
     * POST /api/widget/profile/fcm-token
     *
     * Токен устройства для push. Upsert в customer_push_tokens (у покупателя может
     * быть несколько устройств). last_seen_at обновляется каждый раз — по нему
     * крон push:prune-stale-tokens чистит заброшенные.
     */
    public function updateFcmToken(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'fcm_token' => 'required|string|max:255',
            'platform'  => 'sometimes|in:android,ios',
        ]);

        CustomerPushToken::updateOrCreate(
            ['token' => $data['fcm_token']],
            [
                'customer_id'  => $customer->id,
                'platform'     => $data['platform'] ?? 'android',
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['message' => 'ok']);
    }

    /**
     * GET /api/widget/profile/notification-prefs
     *
     * Настраиваемые категории — только поведенческие и промо (см. Notifier,
     * тиры 2 и 3). Транзакционные (статус заказа, доплата, чат) не отключаются
     * и здесь не фигурируют.
     */
    public function getNotificationPrefs(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        return response()->json(['data' => $this->presentPrefs($customer)]);
    }

    /**
     * PUT /api/widget/profile/notification-prefs
     */
    public function updateNotificationPrefs(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'behavioral' => 'required|boolean',
            'campaign'   => 'required|boolean',
        ]);

        $customer->update(['notification_prefs' => [
            'behavioral' => $data['behavioral'],
            'campaign'   => $data['campaign'],
        ]]);

        return response()->json(['data' => $this->presentPrefs($customer)]);
    }

    private function presentPrefs(Customer $customer): array
    {
        return [
            'behavioral' => $customer->wantsNotificationCategory('behavioral'),
            'campaign'   => $customer->wantsNotificationCategory('campaign'),
        ];
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('customer');
    }

    /**
     * bonus_balance пока всегда 0 — колонка появится вместе с программой
     * лояльности (М4 в PLAN.md), интерфейс уже готов её принять.
     */
    private function present(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'avatar_url' => $customer->avatar_url,
            'total_orders' => $customer->total_orders,
            'total_spent' => $customer->total_spent,
            'bonus_balance' => 0,
        ];
    }
}
