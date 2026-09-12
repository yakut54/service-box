<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChainShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ гейтится middleware 'chain' на маршруте, тут только форма.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'domain'   => 'nullable|url',
            'timezone' => 'nullable|string|timezone',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'Укажите название точки',
            'domain.url'         => 'Укажите корректный адрес сайта',
            'timezone.timezone'  => 'Укажите корректный часовой пояс',
        ];
    }
}
