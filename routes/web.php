<?php

use App\Http\Controllers\LegalController;
use Illuminate\Support\Facades\Route;

// Страница реквизитов — требуется для верификации платёжных систем (YooKassa и др.)
Route::get('/kontakty', function () {
    return view('kontakty');
});

// Страница результата платежа — возврат после ЮКасса
Route::get('/payment/result', function () {
    return view('payment-result');
});

// Юридические документы магазина (server-rendered, SEO-friendly, print-friendly)
Route::get('/legal/{apiKey}/{type}', [LegalController::class, 'show'])
    ->name('legal.document')
    ->whereUuid('apiKey')
    ->where('type', 'offer|privacy|personal-data|marketing');
