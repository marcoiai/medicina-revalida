<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\SimulationController;
use App\Http\Controllers\UserController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/google/url', [AuthController::class, 'googleUrl']);
    Route::get('/google/callback', [AuthController::class, 'googleCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateMe']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::get('/questions/summary', [QuestionController::class, 'summary']);
Route::get('/questions', [QuestionController::class, 'index']);
Route::post('/questions', [QuestionController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/simulations', [SimulationController::class, 'index']);
    Route::post('/simulations', [SimulationController::class, 'store']);
    Route::get('/users', [UserController::class, 'index']);
});
