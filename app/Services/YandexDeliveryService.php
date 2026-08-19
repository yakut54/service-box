<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Обёртка над Яндекс.Доставка API (B2B Cargo). Реализовано по официальной
 * документации (проверено 2026-08-20, см. PLAN.md → М5):
 * https://yandex.ru/support/delivery-profile/ru/api/express/overview
 *
 * Токен ждём от Яндекса — ни один метод здесь не был вызван вживую.
 * Схема запросов/ответов — по документации, не по факту; при первом
 * реальном вызове возможны расхождения в редких полях.
 */
class YandexDeliveryService
{
    private const BASE_URL = 'https://b2b.taxi.yandex.net/b2b/cargo/integration/v2';

    public function __construct(private readonly string $apiToken)
    {
        if ($this->apiToken === '') {
            throw new RuntimeException('Не задан токен Яндекс.Доставки');
        }
    }

    /**
     * offers/calculate — расчёт стоимости и сроков до создания заявки.
     * Оффер живёт ~10 минут (offer_ttl в ответе) — createClaim() нужно
     * вызвать, пока он не истёк.
     *
     * @param array{id:int,coordinates:array{float,float},fullname:string} $routePoints
     *   Минимум 2 точки: точка 1 — склад/самовывоз, остальные — адреса доставки.
     * @param array{pickup_point:int,dropoff_point:int,weight:float,size:array{length:float,width:float,height:float},quantity:int} $items
     * @return array{offers: array} Сырой ответ API — offers[] с price/payload/offer_ttl.
     */
    public function calculateOffer(array $routePoints, array $items, string $taxiClass = 'courier'): array
    {
        return $this->request('/offers/calculate', [
            'route_points' => $routePoints,
            'items' => $items,
            'requirements' => ['taxi_classes' => [$taxiClass]],
        ]);
    }

    /**
     * claims/create — создать заявку. requestId — идемпотентность (Яндекс
     * не создаст дубль при повторной отправке с тем же значением).
     *
     * @param array $items        Как в calculateOffer(), плюс title/cost_value/cost_currency.
     * @param array $routePoints  Полная форма (contact, address) — см. документацию, отличается
     *                            от укороченной формы в calculateOffer().
     * @return array Сырой ответ API — id (claim_id), status, version, ...
     */
    public function createClaim(array $items, array $routePoints, ?string $requestId = null, array $extra = []): array
    {
        return $this->request('/claims/create', [
            'request_id' => $requestId ?? Str::uuid()->toString(),
            'items' => $items,
            'route_points' => $routePoints,
            ...$extra,
        ]);
    }

    /**
     * claims/accept — подтвердить заявку (курьер начинает искаться).
     * version — из ответа createClaim()/getClaimInfo(), не произвольное число.
     */
    public function acceptClaim(string $claimId, int $version): array
    {
        return $this->request("/claims/accept?claim_id={$claimId}", [
            'version' => $version,
        ]);
    }

    /**
     * claims/info — статус заявки для трекинга. Полный список статусов —
     * см. STATUSES ниже.
     */
    public function getClaimInfo(string $claimId): array
    {
        return $this->request("/claims/info?claim_id={$claimId}", []);
    }

    /**
     * claims/cancel — отменить заявку. cancelState — из claims/cancel-info
     * (какая отмена доступна: free/paid — на ранних статусах бесплатно,
     * позже может быть платно).
     */
    public function cancelClaim(string $claimId, string $version, string $cancelState): array
    {
        return $this->request("/claims/cancel?claim_id={$claimId}", [
            'version' => $version,
            'cancel_state' => $cancelState,
        ]);
    }

    /**
     * Все возможные значения claims/info.status — для маппинга на статусы
     * заказа в orders.status. Ключевые для нас: ready_for_approval (можно
     * acceptClaim), performer_found/delivering (курьер едет), delivered.
     */
    public const STATUSES = [
        'new', 'estimating', 'estimating_failed', 'ready_for_approval',
        'accepted', 'performer_lookup', 'performer_draft', 'performer_found',
        'performer_not_found', 'pickup_arrived', 'ready_for_pickup_confirmation',
        'pickuped', 'delivery_arrived', 'ready_for_delivery_confirmation',
        'delivered', 'delivered_finish', 'returning', 'return_arrived',
        'ready_for_return_confirmation', 'returned', 'returned_finish',
        'failed', 'cancelled', 'cancelled_with_payment', 'cancelled_by_taxi',
        'cancelled_with_items_on_hands',
    ];

    private function request(string $path, array $body): array
    {
        $response = Http::withToken($this->apiToken)
            ->withHeaders(['Accept-Language' => 'ru'])
            ->timeout(15)
            ->post(self::BASE_URL.$path, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                'Яндекс.Доставка: '.($response->json('message') ?? $response->status())
            );
        }

        return $response->json() ?? [];
    }
}
