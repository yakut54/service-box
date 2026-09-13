<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChainSettingsRequest extends FormRequest
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
            'logo_url' => 'nullable|url|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название сети',
            'logo_url.url'  => 'Некорректная ссылка на логотип',
        ];
    }
}
