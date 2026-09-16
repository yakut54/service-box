<?php

namespace App\Http\Controllers;

use App\Models\CartActivity;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Активность корзины байера — только счётчик товаров и время последнего
 * изменения, состав корзины на сервер не уходит (см. App\Models\CartActivity).
 * Питает напоминание о брошенной корзине (App\Console\Commands\SendCartReminders).
 * Доступ — только по долгой сессии (см. VerifyPhoneSession), как и профиль.
 */
class CartActivityController extends Controller
{
    /**
     * PUT /api/widget/cart/activity
     *
     * Мобильное приложение шлёт сюда при каждом реальном изменении корзины
     * (с дебаунсом на клиенте). `items_count = 0` — корзина опустела, строка
     * удаляется. Если count не изменился с прошлого раза — ничего не делаем:
     * CartState.notifyListeners() срабатывает и на события, не меняющие сам
     * состав (например, пересчёт скидки), и такое эхо не должно сбрасывать
     * часы заброшенности (first_reminded_at/second_reminded_at обнуляются
     * только при РЕАЛЬНОМ изменении count).
     */
    public function update(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'items_count' => 'required|integer|min:0|max:10000',
        ]);
        $count = $data['items_count'];

        if ($count === 0) {
            CartActivity::where('customer_id', $customer->id)->delete();
            return response()->json(['message' => 'ok']);
        }

        $existing = CartActivity::find($customer->id);
        if ($existing && $existing->items_count === $count) {
            return response()->json(['message' => 'ok']);
        }

        CartActivity::updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'items_count'        => $count,
                'first_reminded_at'  => null,
                'second_reminded_at' => null,
            ],
        );

        return response()->json(['message' => 'ok']);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('customer');
    }
}
