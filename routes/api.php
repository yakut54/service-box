<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health Check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'ServiceBox API',
        'timestamp' => now()->toIso8601String(),
        'stage' => 'Step 2 - Laravel Setup',
    ]);
});
