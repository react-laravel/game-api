<?php

use App\Http\Controllers\Api\Auth\SsoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/auth/csrf', [SsoController::class, 'csrf']);
    Route::post('/auth/exchange', [SsoController::class, 'exchange']);

    Route::middleware('game.auth')->group(function (): void {
        Route::get('/user', [SsoController::class, 'user']);
        Route::post('/auth/logout', [SsoController::class, 'logout']);

        Route::post('/broadcasting/auth', function (Request $request) {
            return Broadcast::auth($request);
        });

        require base_path('routes/api/monopoly.php');
    });
});
