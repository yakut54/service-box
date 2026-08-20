<?php

namespace App\Http\Controllers;

use App\Models\Customer;
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
     * Сжатие до 100-150 КБ — на мобилке перед отправкой; здесь только
     * подстраховка от прямых вызовов API мимо приложения.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $request->validate([
            'avatar' => 'required|image|max:500',
        ], [
            'avatar.image' => 'Файл должен быть изображением',
            'avatar.max' => 'Максимальный размер файла — 500 КБ',
        ]);

        $oldUrl = $customer->avatar_url;

        $file = $request->file('avatar');
        $ext = $file->guessExtension() ?? 'jpg';
        $filename = Str::uuid().'.'.$ext;
        $path = $file->storeAs('avatars', $filename, 'public');

        $customer->update(['avatar_url' => Storage::disk('public')->url($path)]);

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
