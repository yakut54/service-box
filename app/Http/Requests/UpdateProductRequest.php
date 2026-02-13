<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'type' => 'sometimes|in:physical,digital,service',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:5000',
            'price' => 'sometimes|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'image_url' => 'nullable|url|max:500',
            'is_active' => 'sometimes|boolean',
            'category' => 'nullable|string|max:100',
            'sort_order' => 'sometimes|integer|min:0',
        ];

        $rules = array_merge($rules, [
            'physical.sku' => 'nullable|string|max:100',
            'physical.stock_quantity' => 'sometimes|integer|min:0',
            'physical.allow_backorder' => 'sometimes|boolean',
            'physical.weight_grams' => 'nullable|integer|min:0',
            'physical.length_cm' => 'nullable|numeric|min:0',
            'physical.width_cm' => 'nullable|numeric|min:0',
            'physical.height_cm' => 'nullable|numeric|min:0',

            'digital.delivery_type' => 'sometimes|in:download,link,code',
            'digital.access_days' => 'nullable|integer|min:1',
            'digital.download_url' => 'nullable|url|max:500',
            'digital.file_size_bytes' => 'nullable|integer|min:0',

            'service.duration_minutes' => 'sometimes|integer|min:1|max:1440',
            'service.max_concurrent' => 'sometimes|integer|min:1|max:100',
            'service.requires_booking' => 'sometimes|boolean',
        ]);

        return $rules;
    }
}
