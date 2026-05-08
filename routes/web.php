<?php

use App\Http\Controllers\LegalController;
use App\Models\Shop;
use Illuminate\Support\Facades\Route;

// Страница реквизитов — требуется для верификации платёжных систем (YooKassa и др.)
Route::get('/kontakty', function () {
    return view('kontakty');
});

// Страница результата платежа — возврат после ЮКасса
Route::get('/payment/result', function () {
    return view('payment-result');
});

// Платформенные юридические документы (от имени ИП — для шоперов/пользователей платформы)
Route::get('/offer',   fn() => view('platform.offer'))->name('platform.offer');
Route::get('/privacy', fn() => view('platform.privacy'))->name('platform.privacy');

// Страница онлайн-записи для клиентов (ссылка из Telegram)
Route::get('/book/{apiKey}', function (string $apiKey) {
    $shop = Shop::where('api_key', $apiKey)->firstOrFail();
    return view('book', ['shopName' => $shop->name, 'apiKey' => $shop->api_key]);
})->whereUuid('apiKey');

// Юридические документы магазина (server-rendered, SEO-friendly, print-friendly)
Route::get('/legal/{apiKey}/{type}', [LegalController::class, 'show'])
    ->name('legal.document')
    ->whereUuid('apiKey')
    ->where('type', 'offer|privacy|personal-data|marketing');
