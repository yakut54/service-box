<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'ServiceBoxBot'),
        'secret_token' => env('TELEGRAM_SECRET_TOKEN'),
    ],

    'max' => [
        'bot_token'      => env('MAX_BOT_TOKEN'),
        'bot_username'   => env('MAX_BOT_USERNAME'),
        'webhook_secret' => env('MAX_WEBHOOK_SECRET'),
        'api_url'        => 'https://platform-api.max.ru',
    ],

    'yookassa' => [
        'shop_id'    => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
    ],

    'firebase' => [
        // Путь к JSON сервис-аккаунта Firebase (Project Settings → Service
        // Accounts → Generate new private key). Нет файла — push-канал молча
        // выключен (тот же паттерн, что MAIL_MAILER=log до настройки SMTP).
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
    ],

];
