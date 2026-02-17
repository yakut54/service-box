<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    /**
     * Get public shop info (для виджета)
     *
     * GET /api/widget/shop
     */
    public function getPublicInfo(Request $request): JsonResponse
    {
        $shopId = $request->header('X-Shop-ID');
        $shop = Shop::where('api_key', $shopId)->first();

        if (!$shop) {
            return response()->json([
                'error' => 'Shop not found',
            ], 404);
        }

        return response()->json([
            'id' => $shop->id,
            'name' => $shop->name,
            'widget_config' => $shop->widget_config,
        ]);
    }

    /**
     * Get shop details (для админки с Bearer token)
     *
     * GET /api/admin/shop
     */
    public function show(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'error' => 'Shop not found',
            ], 404);
        }

        return response()->json($shop);
    }

    /**
     * Update shop (для админки с Bearer token)
     *
     * PUT /api/admin/shop
     */
    public function update(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json([
                'error' => 'Shop not found',
            ], 404);
        }

        $validated = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'domain'            => 'sometimes|nullable|string|max:255',
            'widget_config'     => 'sometimes|array',
            'yookassa_shop_id'  => 'sometimes|nullable|string',
            'yookassa_secret_key' => 'sometimes|nullable|string',
            'robokassa_login'   => 'sometimes|nullable|string',
            'robokassa_password1' => 'sometimes|nullable|string',
            'robokassa_password2' => 'sometimes|nullable|string',
            'payment_provider'  => 'sometimes|nullable|string',
            'telegram_bot_token' => 'sometimes|nullable|string',
            'telegram_chat_id'  => 'sometimes|nullable|string',
            'work_start'        => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'work_end'          => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'slot_duration'     => 'sometimes|integer|in:10,15,20,30,45,60',
        ]);

        $shop->update($validated);

        return response()->json($shop);
    }
}
