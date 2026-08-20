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
     * @return array{payment_id: string, payment_url: string, status: string}
     */
    public function createPayment(
        int|float $amount,
        string $description,
        string $returnUrl,
        array  $metadata = []
    ): array {
        $idempotenceKey = Str::uuid()->toString();

        $response = $this->client->createPayment([
            'amount' => [
                'value'    => number_format($amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type'       => 'redirect',
                'return_url' => $returnUrl,
            ],
            'capture'     => true,
            'description' => $description,
            'metadata'    => $metadata,
        ], $idempotenceKey);

        return [
            'payment_id'  => $response->getId(),
            'payment_url' => $response->getConfirmation()->getConfirmationUrl(),
            'status'      => $response->getStatus(),
        ];
    }
}
