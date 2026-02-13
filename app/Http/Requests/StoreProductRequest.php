<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'type' => 'required|in:physical,digital,service',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'price' => 'required|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'image_url' => 'nullable|url|max:500',
            'is_active' => 'nullable|boolean',
            'category' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ];

        if ($this->type === 'physical') {
            $rules = array_merge($rules, [
                'physical.sku' => 'nullable|string|max:100',
                'physical.stock_quantity' => 'required|integer|min:0',
                'physical.allow_backorder' => 'nullable|boolean',
                'physical.weight_grams' => 'nullable|integer|min:0',
                'physical.length_cm' => 'nullable|numeric|min:0',
                'physical.width_cm' => 'nullable|numeric|min:0',
                'physical.height_cm' => 'nullable|numeric|min:0',
            ]);
        }

        if ($this->type === 'digital') {
            $rules = array_merge($rules, [
                'digital.delivery_type' => 'required|in:download,link,code',
                'digital.access_days' => 'nullable|integer|min:1',
                'digital.download_url' => 'nullable|url|max:500',
                'digital.file_size_bytes' => 'nullable|integer|min:0',
            ]);
        }

        if ($this->type === 'service') {
            $rules = array_merge($rules, [
                'service.duration_minutes' => 'required|integer|min:1|max:1440',
                'service.max_concurrent' => 'nullable|integer|min:1|max:100',
                'service.requires_booking' => 'nullable|boolean',
            ]);
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Укажите тип товара',
            'type.in' => 'Тип товара должен быть: physical, digital или service',
            'name.required' => 'Укажите название товара',
            'price.required' => 'Укажите цену товара',
            'price.integer' => 'Цена должна быть целым числом (в копейках)',
            'physical.stock_quantity.required' => 'Укажите количество на складе',
            'digital.delivery_type.required' => 'Укажите способ доставки',
            'service.duration_minutes.required' => 'Укажите длительность услуги',
        ];
    }
}
