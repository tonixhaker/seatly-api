<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrganizerController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:10,1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
});

Route::get('events', [CatalogController::class, 'index']);
Route::get('events/{id}', [CatalogController::class, 'show'])->where('id', '[0-9]{1,18}');
Route::get('events/{id}/seats', [CatalogController::class, 'seats'])->where('id', '[0-9]{1,18}');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::middleware('role:buyer')->group(function (): void {
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{id}', [OrderController::class, 'show'])->whereUuid('id');
        Route::get('my/tickets', [OrderController::class, 'tickets']);
    });

    Route::middleware('role:organizer')->group(function (): void {
        Route::post('organizer/events', [OrganizerController::class, 'store']);
        Route::put('organizer/events/{id}', [OrganizerController::class, 'update'])->where('id', '[0-9]{1,18}');
        Route::post('organizer/events/{id}/publish', [OrganizerController::class, 'publish'])->where('id', '[0-9]{1,18}');
        Route::get('organizer/events/{id}/stats', [OrganizerController::class, 'stats'])->where('id', '[0-9]{1,18}');
        Route::post('organizer/check-in', [OrganizerController::class, 'checkIn']);
    });
});
