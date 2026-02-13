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

        $shop->update($request->only([
            'name',
            'domain',
            'widget_config',
            'yookassa_shop_id',
            'yookassa_secret_key',
            'robokassa_login',
            'robokassa_password1',
            'robokassa_password2',
            'payment_provider',
            'telegram_bot_token',
            'telegram_chat_id',
        ]));

        return response()->json($shop);
    }
}
