<?php

namespace App\Services;

use Illuminate\Support\Str;
use YooKassa\Client;

class YooKassaService
{
    private Client $client;

    /**
     * Создать сервис с кредами шопа или из .env (для подписок ServiceBox).
     */
    public function __construct(?string $shopId = null, ?string $secretKey = null)
    {
        $this->client = new Client();
        $this->client->setAuth(
            $shopId    ?? config('services.yookassa.shop_id'),
            $secretKey ?? config('services.yookassa.secret_key'),
        );
    }

    /**
     * Создать платёж в ЮКасса.
     *
     * @param  int|float $amount      Сумма в рублях (вызывающий код сам делит копейки на 100)
     * @param  string    $description Описание для пользователя
     * @param  string    $returnUrl   Куда редиректить после оплаты
     * @param  array     $metadata    Произвольные данные (shop_id, order_id и т.д.)
     * @param  bool      $capture     false — двухстадийная оплата (холд): деньги
     *                                блокируются, но списываются только по
     *                                отдельному вызову capturePayment(). Нужно для
     *                                режима «По весу — перевзвешивание», где точная
     *                                сумма известна только после сборки заказа.
     * @param  ?bool     $savePaymentMethod  true — попросить ЮKassa сохранить способ
     *                                оплаты для будущего автосписания без участия
     *                                покупателя (см. chargeWithSavedMethod) — только
     *                                для заказов с «по весу — перевзвешивание», там
     *                                потом может понадобиться доплата. null — не
     *                                передавать поле вообще (поведение как раньше).
     * @return array{payment_id: string, payment_url: string, status: string}
     */
    public function createPayment(
        int|float $amount,
        string $description,
        string $returnUrl,
        array  $metadata = [],
        bool   $capture = true,
        ?bool  $savePaymentMethod = null,
    ): array {
        $idempotenceKey = Str::uuid()->toString();

        $request = [
            'amount' => [
                'value'    => number_format($amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type'       => 'redirect',
                'return_url' => $returnUrl,
            ],
            'capture'     => $capture,
            'description' => $description,
            'metadata'    => $metadata,
        ];
        if ($savePaymentMethod !== null) {
            $request['save_payment_method'] = $savePaymentMethod;
        }

        $response = $this->client->createPayment($request, $idempotenceKey);

        return [
            'payment_id'  => $response->getId(),
            'payment_url' => $response->getConfirmation()->getConfirmationUrl(),
            'status'      => $response->getStatus(),
        ];
    }

    /**
     * Списать доплату за перевес с уже сохранённого способа оплаты — без
     * редиректа покупателя на страницу ЮKassa (см. OrderReweighService::finalizeOrder).
     * Подтверждение/провал приходит тем же вебхуком, что и обычная доплата по
     * ссылке (PaymentController::handleYooKassaWebhook, type=order_surcharge) —
     * с точки зрения вебхука неважно, как именно был создан платёж.
     *
     * Упадёт исключением, если у магазина в ЮKassa не включены автоплатежи
     * (это разрешение конкретного магазина, не галочка в коде) или
     * payment_method_id больше не действителен — вызывающий код обязан
     * ловить это и откатываться на обычный сценарий со ссылкой.
     */
    public function chargeWithSavedMethod(
        string $paymentMethodId,
        int|float $amountRubles,
        string $description,
        array $metadata = [],
    ): array {
        $response = $this->client->createPayment([
            'amount' => [
                'value'    => number_format($amountRubles, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'payment_method_id' => $paymentMethodId,
            'capture'           => true,
            'description'       => $description,
            'metadata'          => $metadata,
        ], Str::uuid()->toString());

        return [
            'payment_id' => $response->getId(),
            'status'     => $response->getStatus(),
        ];
    }

    /**
     * Списать холд (полностью или частично). Сумма меньше холда — ЮKassa сама
     * размораживает остаток, отдельно отменять его не нужно.
     *
     * @param  string    $paymentId    ID платежа в статусе waiting_for_capture
     * @param  int|float $amountRubles Сумма к списанию, в рублях
     */
    public function capturePayment(string $paymentId, int|float $amountRubles): array
    {
        $response = $this->client->capturePayment([
            'amount' => [
                'value'    => number_format($amountRubles, 2, '.', ''),
                'currency' => 'RUB',
            ],
        ], $paymentId, Str::uuid()->toString());

        return [
            'payment_id' => $response->getId(),
            'status'     => $response->getStatus(),
        ];
    }

    /**
     * Частичный возврат уже списанного платежа — например сборщик не нашёл
     * часть штучного товара при сборке (см. OrderController::updateStatus,
     * недобор по picked_qty). В отличие от capturePayment (снять МЕНЬШЕ с
     * холда, который ещё не списан), здесь деньги уже реально у магазина —
     * возврат идёт отдельной операцией через ЮKassa.
     */
    public function refund(string $paymentId, int|float $amountRubles): array
    {
        $response = $this->client->createRefund([
            'payment_id' => $paymentId,
            'amount' => [
                'value'    => number_format($amountRubles, 2, '.', ''),
                'currency' => 'RUB',
            ],
        ], Str::uuid()->toString());

        return [
            'refund_id' => $response->getId(),
            'status'    => $response->getStatus(),
        ];
    }

    /**
     * Полностью снять холд, ничего не списывая — например если сборщик отменил
     * все весовые позиции заказа (товара не оказалось).
     */
    public function cancelPayment(string $paymentId): array
    {
        $response = $this->client->cancelPayment($paymentId, Str::uuid()->toString());

        return [
            'payment_id' => $response->getId(),
            'status'     => $response->getStatus(),
        ];
    }
}
