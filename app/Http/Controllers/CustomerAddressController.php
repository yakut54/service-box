<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Сохранённые адреса доставки байера. Доступ — только по долгой сессии
 * (см. VerifyPhoneSession), не по 30-минутному OTP-токену виджета.
 */
class CustomerAddressController extends Controller
{
    /**
     * GET /api/widget/addresses
     */
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $addresses = $customer->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $addresses]);
    }

    /**
     * POST /api/widget/addresses
     */
    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'label' => 'nullable|string|max:100',
            'city' => 'required|string|max:100',
            'street' => 'required|string|max:255',
            'building' => 'required|string|max:20',
            'apartment' => 'nullable|string|max:20',
            'postal_code' => 'nullable|string|max:10',
        ], [
            'city.required' => 'Укажите город',
            'street.required' => 'Укажите улицу',
            'building.required' => 'Укажите дом',
        ]);

        $isFirstAddress = !$customer->addresses()->exists();

        $address = $customer->addresses()->create([
            ...$data,
            'is_default' => $isFirstAddress,
        ]);

        return response()->json(['data' => $address], 201);
    }

    /**
     * PUT /api/widget/addresses/{address}/default
     */
    public function setDefault(Request $request, string $address): JsonResponse
    {
        $customer = $this->customer($request);
        $addressModel = $this->findOwnedAddress($customer, $address);

        if (!$addressModel) {
            return response()->json(['error' => 'Адрес не найден'], 404);
        }

        $customer->addresses()->where('id', '!=', $addressModel->id)->update(['is_default' => false]);
        $addressModel->update(['is_default' => true]);

        return response()->json(['data' => $addressModel->fresh()]);
    }

    /**
     * DELETE /api/widget/addresses/{address}
     */
    public function destroy(Request $request, string $address): JsonResponse
    {
        $customer = $this->customer($request);
        $addressModel = $this->findOwnedAddress($customer, $address);

        if (!$addressModel) {
            return response()->json(['error' => 'Адрес не найден'], 404);
        }

        $wasDefault = $addressModel->is_default;
        $addressModel->delete();

        // Если удалили адрес по умолчанию — назначаем им самый свежий из оставшихся,
        // чтобы у клиента не осталось адресов вообще без дефолтного.
        if ($wasDefault) {
            $customer->addresses()->orderByDesc('created_at')->first()?->update(['is_default' => true]);
        }

        return response()->json(['message' => 'Адрес удалён']);
    }

    private function customer(Request $request): Customer
    {
        return $request->attributes->get('customer');
    }

    private function findOwnedAddress(Customer $customer, string $addressId): ?CustomerAddress
    {
        return $customer->addresses()->where('id', $addressId)->first();
    }
}
