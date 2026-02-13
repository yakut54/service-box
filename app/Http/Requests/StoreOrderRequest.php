<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid',
            'items.*.quantity' => 'required|integer|min:1|max:999',

            'customer.name' => 'required|string|max:255',
            'customer.email' => 'required|email|max:255',
            'customer.phone' => 'required|string|max:20',

            'shipping_address' => 'nullable|array',
            'shipping_address.city' => 'required_with:shipping_address|string|max:100',
            'shipping_address.street' => 'required_with:shipping_address|string|max:255',
            'shipping_address.building' => 'required_with:shipping_address|string|max:20',
            'shipping_address.apartment' => 'nullable|string|max:20',
            'shipping_address.postal_code' => 'required_with:shipping_address|string|max:10',
            'shipping_address.comment' => 'nullable|string|max:500',

            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Добавьте хотя бы один товар в заказ',
            'items.*.product_id.required' => 'Укажите ID товара',
            'items.*.product_id.uuid' => 'Неверный формат ID товара',
            'items.*.quantity.required' => 'Укажите количество',
            'items.*.quantity.min' => 'Минимальное количество: 1',
            'customer.name.required' => 'Укажите имя',
            'customer.email.required' => 'Укажите email',
            'customer.email.email' => 'Неверный формат email',
            'customer.phone.required' => 'Укажите телефон',
            'shipping_address.city.required_with' => 'Укажите город',
            'shipping_address.street.required_with' => 'Укажите улицу',
            'shipping_address.building.required_with' => 'Укажите дом',
            'shipping_address.postal_code.required_with' => 'Укажите индекс',
        ];
    }
}
