<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\SteamAuthController;
use App\Http\Middleware\RequireSteamAuth;
use Illuminate\Support\Facades\Route;

Route::get('/auth/steam', [SteamAuthController::class, 'login'])->name('steam.login');
Route::get('/auth/steam/callback', [SteamAuthController::class, 'callback'])->name('steam.callback');

Route::get('/', HomeController::class)->middleware(RequireSteamAuth::class);
