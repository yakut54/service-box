<?php

use Illuminate\Support\Facades\Route;

// Страница реквизитов — требуется для верификации платёжных систем (YooKassa и др.)
Route::get('/kontakty', function () {
    return view('kontakty');
});

// Страница результата платежа — возврат после ЮКасса
Route::get('/payment/result', function () {
    return view('payment-result');
});
