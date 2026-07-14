<?php

use App\Http\Controllers\Api\Monopoly\MonopolyController;
use Illuminate\Support\Facades\Route;

Route::prefix('monopoly')->group(function () {
    Route::get('/rooms', [MonopolyController::class, 'index']);
    Route::post('/rooms', [MonopolyController::class, 'store']);
    Route::get('/rooms/{room}', [MonopolyController::class, 'show']);
    Route::post('/rooms/{room}/join', [MonopolyController::class, 'join']);
    Route::post('/rooms/{room}/leave', [MonopolyController::class, 'leave']);
    Route::post('/rooms/{room}/computers', [MonopolyController::class, 'addComputer']);
    Route::delete('/rooms/{room}/computers/{playerId}', [MonopolyController::class, 'removeComputer']);
    Route::post('/rooms/{room}/start', [MonopolyController::class, 'start']);
    Route::post('/rooms/{room}/roll', [MonopolyController::class, 'roll']);
    Route::post('/rooms/{room}/buy', [MonopolyController::class, 'buy']);
    Route::post('/rooms/{room}/build', [MonopolyController::class, 'build']);
    Route::post('/rooms/{room}/end-turn', [MonopolyController::class, 'endTurn']);
    Route::post('/rooms/{room}/leave-jail', [MonopolyController::class, 'leaveJail']);
});
