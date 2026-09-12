<?php

use App\Http\Controllers\Api\PageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.cors', 'api.security', 'throttle:60,1'])->group(function () {
    Route::get('/health', [PageController::class, 'health']);
    Route::get('/home', [PageController::class, 'home']);
    Route::get('/movies', [PageController::class, 'movies']);
    Route::get('/movie/{id}', [PageController::class, 'movie']);
    Route::get('/favorites', [PageController::class, 'favorites']);
    Route::post('/favorites/toggle', [PageController::class, 'toggleFavorite']);
    Route::get('/transactions', [PageController::class, 'transactions']);
    Route::get('/notifications', [PageController::class, 'notifications']);
    Route::get('/profile', [PageController::class, 'profile']);
    Route::get('/settings', [PageController::class, 'settings']);

    Route::post('/auth/login', [PageController::class, 'login']);
    Route::post('/auth/register', [PageController::class, 'register']);
    Route::post('/reservations/create', [PageController::class, 'createReservation']);
    Route::post('/reservations/{id}/cancel', [PageController::class, 'cancelReservation']);
    Route::post('/reservations/{id}/complete', [PageController::class, 'completeReservation']);
    Route::put('/profile/update', [PageController::class, 'updateProfile']);
    Route::post('/admin/movies/upload', [PageController::class, 'uploadMovieImage']);
    Route::post('/admin/movies', [PageController::class, 'adminStore']);
});
