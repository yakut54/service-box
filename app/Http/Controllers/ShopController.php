<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        $rawDelivery    = $shop->delivery_settings ?? [];
        $enabledMethods = array_filter($rawDelivery, fn($m) => !empty($m['enabled']));

        return response()->json([
            'id'                  => $shop->id,
            'name'                => $shop->name,
            'widget_config'       => $shop->widget_config,
            'timezone'            => $shop->timezone,
            'prepayment_enabled'  => (bool) $shop->prepayment_enabled,
            'prepayment_amount'   => (int) $shop->prepayment_amount,
            'delivery_settings'   => $enabledMethods ?: null,
            'legal'             => [
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
            'widget_config'                     => 'sometimes|array',
            'widget_config.preset'              => 'sometimes|nullable|string|in:light,dark,minimal',
            'widget_config.logo_url'            => 'sometimes|nullable|string|max:1000',
            'widget_config.primary_color'       => 'sometimes|nullable|string|max:20',
            'widget_config.border_radius'       => 'sometimes|nullable|integer|in:4,8,16',
            'widget_config.font_family'         => 'sometimes|nullable|string|in:system,inter,roboto,montserrat,georgia',
            'widget_config.sidebar_position'    => 'sometimes|nullable|string|in:left,right',
            'widget_config.bg_color'            => 'sometimes|nullable|string|max:20',
            'widget_config.page_bg_color'       => 'sometimes|nullable|string|max:20',
            'widget_config.text_color'          => 'sometimes|nullable|string|max:20',
            'widget_config.show_price'          => 'sometimes|boolean',
            'widget_config.show_duration'       => 'sometimes|boolean',
            'widget_config.show_master_name'    => 'sometimes|boolean',
            'widget_config.show_description'    => 'sometimes|boolean',
            'widget_config.white_label'         => 'sometimes|boolean',
            'widget_config.custom_css'          => 'sometimes|nullable|string|max:10000',
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
            'slot_duration'      => 'sometimes|integer|in:10,15,20,30,45,60',
            'min_booking_notice'  => 'sometimes|integer|in:0,30,60,120,180,360,720,1440,2880',
            'timezone'            => 'sometimes|string|timezone',
            'prepayment_enabled'  => 'sometimes|boolean',
            'prepayment_amount'   => 'sometimes|integer|min:0',
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
        $wc = $validated['widget_config'] ?? [];
        if (!empty($wc) && !$shop->hasFeature('widget_customization')) {
            return response()->json([
                'error'   => 'plan_gate',
                'message' => 'Кастомизация виджета доступна на тарифе Business и выше.',
            ], 403);
        }

        // custom_css / white_label — только для Pro
        if ((isset($wc['custom_css']) || isset($wc['white_label'])) && !$shop->hasFeature('custom_css')) {
            return response()->json([
                'error'   => 'plan_gate',
                'message' => 'Custom CSS и white label доступны на тарифе Pro.',
            ], 403);
        }

        // Защита от CSS-инъекции: запрещаем закрывающий тег </style> и javascript:
        if (isset($wc['custom_css'])) {
            $css = $wc['custom_css'];
            if (preg_match('/<\/style\s*>/i', $css) || preg_match('/javascript\s*:/i', $css)) {
                return response()->json([
                    'error'   => 'invalid_css',
                    'message' => 'Недопустимое содержимое в Custom CSS.',
                ], 422);
            }
        }

        // Мержим widget_config с существующими данными
        if (isset($validated['widget_config'])) {
            $oldLogoUrl      = ($shop->widget_config ?? [])['logo_url'] ?? null;
            $incomingLogoUrl = $validated['widget_config']['logo_url'] ?? null;

            $existing = $shop->widget_config ?? [];
            $validated['widget_config'] = array_merge($existing, $validated['widget_config']);

            // Удаляем старый логотип если он был заменён
            if (array_key_exists('logo_url', $request->input('widget_config', [])) && $oldLogoUrl && $oldLogoUrl !== $incomingLogoUrl) {
                StorageService::deleteByUrl($oldLogoUrl);
            }
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

    /**
     * Regenerate API key (Pro plan only)
     *
     * POST /api/admin/api-key/regenerate
     */
    public function regenerateApiKey(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop->hasFeature('api_access')) {
            return response()->json(['message' => 'API access is available on the Pro plan.'], 403);
        }

        $shop->api_key = Str::uuid()->toString();
        $shop->save();

        return response()->json(['api_key' => $shop->api_key]);
    }
}
