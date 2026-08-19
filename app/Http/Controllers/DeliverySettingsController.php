<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliverySettingsController extends Controller
{
    private function defaults(): array
    {
        return [
            'pickup'  => ['enabled' => false, 'price' => 0,     'address'   => ''],
            'courier' => ['enabled' => false, 'price' => 0,     'free_from' => null],
            'postal'  => ['enabled' => false, 'price' => 0,     'free_from' => null],
            // Автоматическая доставка через Яндекс.Доставку — отдельно от
            // ручных способов выше: цена не задаётся шопером, её считает
            // Яндекс по API (см. YandexDeliveryService). enabled здесь —
            // намерение шопера подключить, не признак что реально работает
            // (для этого ещё нужен валидный api_token — см. status в ответе).
            'yandex'  => ['enabled' => false, 'api_token' => null, 'warehouse_address' => '', 'free_from' => null],
        ];
    }

    public function show(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $settings = array_replace_recursive($this->defaults(), $shop->delivery_settings ?? []);
        return response()->json(['data' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $request->validate([
            'pickup.enabled'   => 'boolean',
            'pickup.price'     => 'integer|min:0',
            'pickup.address'   => 'nullable|string|max:255',
            'courier.enabled'  => 'boolean',
            'courier.price'    => 'integer|min:0',
            'courier.free_from'=> 'nullable|integer|min:0',
            'postal.enabled'   => 'boolean',
            'postal.price'     => 'integer|min:0',
            'postal.free_from' => 'nullable|integer|min:0',
            'yandex.enabled'            => 'boolean',
            'yandex.api_token'          => 'nullable|string|max:500',
            'yandex.warehouse_address'  => 'nullable|string|max:255',
            'yandex.free_from'          => 'nullable|integer|min:0',
        ]);

        $merged = array_replace_recursive(
            $shop->delivery_settings ?? $this->defaults(),
            $request->only(['pickup', 'courier', 'postal', 'yandex'])
        );

        $shop->update(['delivery_settings' => $merged]);

        return response()->json(['data' => $merged]);
    }
}
