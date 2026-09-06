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
            'compare_price' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|size:3',
            'image_url' => 'nullable|url|max:500',
            'is_active' => 'nullable|boolean',
            'category_id' => 'nullable|string',
            'size_chart_id' => 'nullable|uuid',
            'sort_order' => 'nullable|integer|min:0',

            // Произвольные характеристики «label: value» (см. ProductController::syncAttributes)
            'attributes' => 'nullable|array|max:30',
            'attributes.*.label' => 'nullable|string|max:255|required_with:attributes.*.value',
            'attributes.*.value' => 'nullable|string|max:1000|required_with:attributes.*.label',

            // Опции + варианты (одежда/обувь) — см. ProductController::syncOptionsAndVariants
            'options' => 'nullable|array|max:3',
            'options.*.name' => 'required_with:options.*.values|string|max:60',
            'options.*.values' => 'nullable|array|max:30',
            'options.*.values.*' => 'required|string|max:60',
            'variants' => 'nullable|array|max:300',
            'variants.*.option_values' => 'required_with:variants|array|max:3',
            'variants.*.option_values.*' => 'nullable|string|max:60',
            'variants.*.sku' => 'nullable|string|max:100',
            'variants.*.price' => 'nullable|integer|min:0',
            'variants.*.stock_quantity' => 'nullable|integer|min:0',
            'variants.*.allow_backorder' => 'nullable|boolean',
            'variants.*.image_url' => 'nullable|string|max:500',
            'variants.*.is_active' => 'nullable|boolean',
        ];

        if ($this->type === 'physical') {
            $rules = array_merge($rules, [
                'physical.sku' => 'nullable|string|max:100',
                // required только для штучного режима — весовые товары ведут
                // остаток в граммах (stock_weight_grams), не в штуках.
                'physical.stock_quantity' => 'nullable|required_if:physical.sale_mode,piece|integer|min:0',
                'physical.allow_backorder' => 'nullable|boolean',
                'physical.weight_grams' => 'nullable|integer|min:0',
                'physical.length_cm' => 'nullable|numeric|min:0',
                'physical.width_cm' => 'nullable|numeric|min:0',
                'physical.height_cm' => 'nullable|numeric|min:0',
                // Развесной товар (PLAN.md, «Развесной товар — финальное ТЗ»)
                'physical.sale_mode' => 'nullable|in:piece,weight_fixed,weight_variable',
                'physical.weight_step_grams' => 'nullable|integer|min:1',
                'physical.weight_min_grams' => 'nullable|integer|min:1',
                'physical.weight_max_grams' => 'nullable|integer|min:1|gte:physical.weight_min_grams',
                // Остаток на складе для весовых товаров (см. PLAN.md, «Остаток
                // на складе для весовых товаров») — поле общее для weight_fixed
                // и weight_variable, но enforce на бэкенде только у первого
                // (второй режим сам как фича ещё не построен).
                'physical.stock_weight_grams' => 'nullable|required_if:physical.sale_mode,weight_fixed,weight_variable|integer|min:0',
                // Многоштучная упаковка + маркировка (Фаза 2)
                'physical.units_per_pack' => 'nullable|integer|min:1|max:100000',
                'physical.unit_label' => 'nullable|string|max:20',
                'physical.marking_code' => 'nullable|string|max:255',
            ]);
        }

        if ($this->type === 'digital') {
            $rules = array_merge($rules, [
                'digital.delivery_type' => 'required|in:download,link,code',
                'digital.access_days' => 'nullable|integer|min:1',
                'digital.download_url' => 'nullable|string|max:500',
                'digital.file_size_mb' => 'nullable|numeric|min:0',
                'digital.file_format' => 'nullable|string|max:50',
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
            'physical.stock_quantity.required_if' => 'Укажите количество на складе',
            'physical.stock_weight_grams.required_if' => 'Укажите остаток на складе (г)',
            'digital.delivery_type.required' => 'Укажите способ доставки',
            'service.duration_minutes.required' => 'Укажите длительность услуги',
        ];
    }
}
