<?php

use App\Http\Controllers\MeController;
use App\Http\Controllers\SteamAuthController;
use App\Http\Middleware\RequireSteamAuth;
use Illuminate\Support\Facades\Route;

Route::get('/auth/steam', [SteamAuthController::class, 'login'])->name('steam.login');
Route::get('/auth/steam/callback', [SteamAuthController::class, 'callback'])->name('steam.callback');

Route::middleware(RequireSteamAuth::class)->group(function () {
    Route::get('/api/me', [MeController::class, 'show']);
    Route::post('/api/logout', [MeController::class, 'logout']);
});
