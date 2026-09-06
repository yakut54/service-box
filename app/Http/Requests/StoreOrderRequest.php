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
            // Товар с вариантами (размер/цвет) присылает id выбранного варианта.
            'items.*.variant_id' => 'nullable|uuid',
            // Штучный товар присылает quantity; весовой (см. PLAN.md, «Развесной
            // товар») — weight_grams вместо quantity. Какое поле обязательно
            // для конкретного товара — решает контроллер, зная его sale_mode.
            'items.*.quantity' => 'sometimes|integer|min:1|max:999',
            'items.*.weight_grams' => 'sometimes|integer|min:1',

            'customer.name' => 'required|string|max:255',
            'customer.email' => 'nullable|email|max:255',
            'customer.phone' => 'required|string|max:20',

            'shipping_address' => 'nullable|array',
            'shipping_address.city' => 'required_with:shipping_address|string|max:100',
            'shipping_address.street' => 'required_with:shipping_address|string|max:255',
            'shipping_address.building' => 'required_with:shipping_address|string|max:20',
            'shipping_address.apartment' => 'nullable|string|max:20',
            'shipping_address.postal_code' => 'nullable|string|max:10',

            'delivery_method'          => 'nullable|in:pickup,courier,postal',
            'delivery_price'           => 'nullable|integer|min:0',

            'notes'                    => 'nullable|string|max:1000',
            'discount_code'            => 'nullable|string|max:50',
            'consent_offer_accepted'   => 'nullable|boolean',
            'consent_privacy_accepted' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Добавьте хотя бы один товар в заказ',
            'items.array' => 'Неверный формат списка товаров',
            'items.min' => 'Добавьте хотя бы один товар в заказ',
            'items.*.product_id.required' => 'Укажите ID товара',
            'items.*.product_id.uuid' => 'Неверный формат ID товара',
            'items.*.quantity.required' => 'Укажите количество',
            'items.*.quantity.integer' => 'Количество должно быть числом',
            'items.*.quantity.min' => 'Минимальное количество: 1',
            'items.*.quantity.max' => 'Максимальное количество: 999',

            'customer.name.required' => 'Укажите имя',
            'customer.name.max' => 'Имя не должно превышать 255 символов',
            'customer.email.email' => 'Неверный формат email',
            'customer.email.max' => 'Email не должен превышать 255 символов',
            'customer.phone.required' => 'Укажите телефон',
            'customer.phone.max' => 'Телефон не должен превышать 20 символов',

            'shipping_address.city.required_with' => 'Укажите город',
            'shipping_address.city.max' => 'Слишком длинное название города',
            'shipping_address.street.required_with' => 'Укажите улицу',
            'shipping_address.street.max' => 'Слишком длинное название улицы',
            'shipping_address.building.required_with' => 'Укажите дом',
            'shipping_address.building.max' => 'Слишком длинный номер дома',
            'shipping_address.apartment.max' => 'Слишком длинный номер квартиры',
            'shipping_address.postal_code.max' => 'Индекс не должен превышать 10 символов',

            'delivery_method.in' => 'Неверный способ доставки',
            'delivery_price.integer' => 'Стоимость доставки должна быть числом',
            'delivery_price.min' => 'Стоимость доставки не может быть отрицательной',

            'notes.max' => 'Комментарий не должен превышать 1000 символов',
            'discount_code.max' => 'Слишком длинный промокод',
        ];
    }
}
