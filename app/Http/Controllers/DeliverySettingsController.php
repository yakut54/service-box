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
        ]);

        $merged = array_replace_recursive(
            $shop->delivery_settings ?? $this->defaults(),
            $request->only(['pickup', 'courier', 'postal'])
        );

        $shop->update(['delivery_settings' => $merged]);

        return response()->json(['data' => $merged]);
    }
}
