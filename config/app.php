<?php

/*
|--------------------------------------------------------------------------
| Конфиг приложения — только доопределения
|--------------------------------------------------------------------------
|
| Файла config/app.php в проекте раньше не было — config('app.*') резолвился
| целиком из vendor-дефолта laravel/framework. Laravel 11 делает поверхностный
| merge базового конфига с этим файлом (LoadConfiguration::loadConfigurationFile:
| array_merge($base['app'], $this)), поэтому здесь достаточно перечислить только
| то, что нужно доопределить — key / cipher / env / debug / previous_keys /
| maintenance подтянутся из базового. Массивы (providers/aliases) в Laravel 11
| в app.php отсутствуют, так что merge-ловушек нет.
|
| Зачем понадобился: config('app.frontend_url') возвращал null (в базовом
| конфиге такого ключа нет) → PaymentController/AuthController/StaffController
| строили относительные ссылки. ЮKassa требует абсолютный return_url, письма
| сброса пароля и инвайтов уходили с битой ссылкой.
|
*/

return [

    // Имя платформы. Подписывает письма (resources/views/emails/*). Без APP_NAME
    // в .env фреймворк подставляет строку 'Laravel'.
    'name' => env('APP_NAME', 'ServiceBox'),

    // ЕДИНСТВЕННАЯ переменная домена. Всё внешнее — ссылки в письмах и ботах,
    // Storage::disk('public')->url(), дефолт Sanctum stateful, CORS (config/cors.php),
    // сборка бандлов admin/widget в deploy.sh, регистрация вебхуков — выводится
    // отсюда. Меняется в одном месте: /var/www/servicebox/.env → APP_URL.
    'url' => env('APP_URL', 'http://localhost'),

    // Куда ведут ссылки, которые открывает человек (сброс пароля, инвайты
    // сотрудников, return_url ЮKassa). В проде фронт отдаётся с того же домена,
    // что и API (nginx.conf, location /), поэтому по умолчанию = APP_URL.
    // FRONTEND_URL нужна только для локалки (фронт на :3000) либо если админку
    // вынесут на поддомен.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', '')), '/') ?: null,

    // Голый хост без схемы — для мест, где нужен домен, а не URL: юридические
    // тексты, contact-email по умолчанию (info@<domain>).
    'domain' => parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: null,

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    // Проектная локаль — русская. Раньше держалась костылём deploy.sh (дописывал
    // APP_LOCALE=ru в .env), теперь дефолт здесь: lang/ru/validation.php,
    // сообщения валидации по-русски даже на чистом сервере без этой строки в .env.
    'locale' => env('APP_LOCALE', 'ru'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

];
