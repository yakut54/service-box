<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->numbers()
            ],
            'shop_name' => 'required|string|max:255',
            'shop_domain' => 'nullable|url',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите ваше имя',
            'email.required' => 'Укажите email',
            'email.email' => 'Неверный формат email',
            'email.unique' => 'Пользователь с таким email уже существует',
            'password.required' => 'Укажите пароль',
            'password.confirmed' => 'Пароли не совпадают',
            'shop_name.required' => 'Укажите название магазина',
        ];
    }
}
