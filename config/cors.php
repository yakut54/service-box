<?php

return [

    /*
     * Только первопартийные префиксы (админка, портал мастера, суперадмин,
     * инвайты, echo-auth). HandleCors подключён ко всей группе `api`
     * (bootstrap/app.php) и перехватывает preflight ДО остальных middleware —
     * поэтому пути, которым нужен `*` (виджет на чужом сайте, внешний API v1),
     * сюда НЕ входят: их закрывает middleware `api.cors` (App\Http\Middleware\ApiCors)
     * на самих группах в routes/api.php.
     *
     * Появился новый первопартийный префикс под /api — добавить его сюда.
     */
    'paths' => [
        'api/auth/*',
        'api/admin/*',
        'api/master/*',
        'api/invite/*',
        'api/superadmin/*',
        'api/broadcasting/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    /*
     * Прод-домен берётся из APP_URL (единственная переменная домена, config/app.php).
     * FRONTEND_URL — если админку вынесут на отдельный поддомен. CORS_ALLOWED_ORIGINS —
     * список через запятую для доп. доверенных origin. localhost — для локальной разработки
     * (Vite admin :3000 / widget :5173).
     */
    'allowed_origins' => array_values(array_filter(array_merge(
        [
            'http://localhost:3000',
            'http://localhost:5173',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:5173',
        ],
        array_filter([env('APP_URL'), env('FRONTEND_URL')]),
        array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))),
    ))),

    'allowed_origins_patterns' => [
        '/^http:\/\/localhost:\d+$/',
        '/^http:\/\/127\.0\.0\.1:\d+$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-Shop-ID',
        'Authorization',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];
