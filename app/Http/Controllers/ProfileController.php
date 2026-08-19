<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Профиль байера в мобильном приложении. Доступ — только по долгой сессии
 * (см. VerifyPhoneSession), как и адреса (CustomerAddressController).
 */
class ProfileController extends Controller
{
    /**
     * GET /api/widget/profile
     */
    public function show(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        return response()->json(['data' => $this->present($customer)]);
    }

    /**
     * PUT /api/widget/profile
     */
    public function update(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
        ], [
            'name.required' => 'Укажите имя',
            'name.min' => 'Минимум 2 символа',
        ]);

        $customer->update(['name' => $data['name']]);

        return response()->json(['data' => $this->present($customer)]);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('customer');
    }

    /**
     * bonus_balance пока всегда 0 — колонка появится вместе с программой
     * лояльности (М4 в PLAN.md), интерфейс уже готов её принять.
     */
    private function present(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'total_orders' => $customer->total_orders,
            'total_spent' => $customer->total_spent,
            'bonus_balance' => 0,
        ];
    }
}
