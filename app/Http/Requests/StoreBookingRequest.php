<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => 'required|uuid',
            'start_time' => 'required|date|after:now',
            'master_id' => 'nullable|uuid',

            'customer.name' => 'required|string|max:255',
            'customer.phone' => 'required|string|max:20',
            'customer.email' => 'nullable|email|max:255',

            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'service_id.required' => 'Выберите услугу',
            'start_time.required' => 'Укажите время записи',
            'start_time.after' => 'Нельзя записаться на прошедшее время',
            'customer.name.required' => 'Укажите имя',
            'customer.phone.required' => 'Укажите телефон',
        ];
    }
}
