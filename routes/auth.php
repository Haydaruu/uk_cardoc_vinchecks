<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleAuthController;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/auth-page', function(){ return Inertia::render('auth/auth-page'); })->name('authPage');

    Route::prefix('auth/google')->name('google.')->controller(GoogleAuthController::class)->group(function () {
        Route::get('/redirect', 'redirect')->name('redirect');
        Route::get('/callback', 'callback')->name('callback');
    });
});
