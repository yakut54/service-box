<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Contracts\View\View;

class LegalController extends Controller
{
    private const DOCUMENT_MAP = [
        'offer' => [
            'title' => 'Публичная оферта',
            'field' => 'public_offer_text',
        ],
        'privacy' => [
            'title' => 'Политика конфиденциальности',
            'field' => 'privacy_policy_text',
        ],
        'personal-data' => [
            'title' => 'Согласие на обработку персональных данных',
            'field' => 'personal_data_consent_text',
        ],
        'marketing' => [
            'title' => 'Согласие на получение уведомлений',
            'field' => 'marketing_consent_text',
        ],
    ];

    /**
     * GET /legal/{apiKey}/{type}
     */
    public function show(string $apiKey, string $type): View
    {
        abort_unless(isset(self::DOCUMENT_MAP[$type]), 404);

        $shop = Shop::where('api_key', $apiKey)->firstOrFail();
        $meta = self::DOCUMENT_MAP[$type];
        $cfg  = $shop->legal_config ?? [];

        return view('legal.document', [
            'shop'     => $shop,
            'title'    => $meta['title'],
            'text'     => $cfg[$meta['field']] ?? null,
            'cfg'      => $cfg,
            'type'     => $type,
            'docTypes' => self::DOCUMENT_MAP,
            'apiKey'   => $apiKey,
        ]);
    }
}
