<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ гейтится middleware 'superadmin' на маршруте, тут только форма.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|unique:users,email',
            'shop_name'   => 'required|string|max:255',
            'shop_domain' => 'nullable|url',
            'timezone'    => 'nullable|string|timezone',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'Укажите имя админа',
            'email.required'     => 'Укажите email',
            'email.email'        => 'Неверный формат email',
            'email.unique'       => 'Пользователь с таким email уже существует',
            'shop_name.required' => 'Укажите название точки',
            'shop_domain.url'    => 'Укажите корректный адрес сайта',
            'timezone.timezone'  => 'Укажите корректный часовой пояс',
        ];
    }
}
