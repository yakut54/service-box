<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    /**
     * Список всех магазинов для демо-страницы
     *
     * GET /api/shops
     */
    public function index(): JsonResponse
    {
        $shops = Shop::orderBy('name')
            ->get(['name']);

        return response()->json($shops);
    }

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

        $cfg = $shop->legal_config ?? [];

        return response()->json([
            'id'            => $shop->id,
            'name'          => $shop->name,
            'widget_config' => $shop->widget_config,
            'timezone'      => $shop->timezone,
            'legal'         => [
                'has_docs'          => $shop->hasLegalDocs(),
                'offer_url'         => route('legal.document', [$shop->api_key, 'offer']),
                'privacy_url'       => route('legal.document', [$shop->api_key, 'privacy']),
                'personal_data_url' => route('legal.document', [$shop->api_key, 'personal-data']),
                'contact_email'     => $cfg['contact_email'] ?? null,
                'contact_phone'     => $cfg['contact_phone'] ?? null,
            ],
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
            'timezone'          => 'sometimes|string|timezone',
            // Widget Pro features
            'widget_config.white_label' => 'sometimes|boolean',
            'widget_config.custom_css'  => 'sometimes|nullable|string|max:10000',
            // Юридические документы
            'legal_config'                                  => 'sometimes|array',
            'legal_config.public_offer_text'                => 'nullable|string|max:50000',
            'legal_config.privacy_policy_text'              => 'nullable|string|max:50000',
            'legal_config.personal_data_consent_text'       => 'nullable|string|max:20000',
            'legal_config.marketing_consent_text'           => 'nullable|string|max:10000',
            'legal_config.requisites'                       => 'nullable|string|max:5000',
            'legal_config.contact_email'                    => 'nullable|email|max:255',
            'legal_config.contact_phone'                    => 'nullable|string|max:30',
        ]);

        // widget_config — только для Business и выше
        if (isset($validated['widget_config']) && !$shop->hasFeature('widget_customization')) {
            return response()->json([
                'error'   => 'plan_gate',
                'message' => 'Кастомизация виджета доступна на тарифе Business и выше.',
            ], 403);
        }

        // custom_css / white_label — только для Pro
        $wc = $validated['widget_config'] ?? [];
        if ((isset($wc['custom_css']) || isset($wc['white_label'])) && !$shop->hasFeature('custom_css')) {
            return response()->json([
                'error'   => 'plan_gate',
                'message' => 'Custom CSS и white label доступны на тарифе Pro.',
            ], 403);
        }

        // Мержим widget_config с существующими данными
        if (isset($validated['widget_config'])) {
            $existing = $shop->widget_config ?? [];
            $validated['widget_config'] = array_merge($existing, $validated['widget_config']);
        }

        // Мержим legal_config с существующими данными — не затираем незатронутые поля
        if (isset($validated['legal_config'])) {
            $existing = $shop->legal_config ?? [];
            $validated['legal_config'] = array_merge($existing, $validated['legal_config']);
            $validated['legal_config']['legal_updated_at'] = now()->toISOString();
        }

        $shop->update($validated);

        return response()->json($shop);
    }
}
