<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use Illuminate\Support\Facades\Route;

Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);

Route::get('events', [CatalogController::class, 'index']);
Route::get('events/{id}', [CatalogController::class, 'show'])->where('id', '[0-9]{1,18}');
Route::get('events/{id}/seats', [CatalogController::class, 'seats'])->where('id', '[0-9]{1,18}');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
});
