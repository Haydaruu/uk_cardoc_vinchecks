<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use App\Http\Controllers\VehicleCheckController;
use App\Http\Controllers\Auth\GoogleAuthController;
use Inertia\Inertia;



Route::get('/', [PageController::class, 'home'])
    ->middleware('redirect.home.auth')    
    ->name('page.home');
Route::get('/support', [PageController::class, 'support'])->name('page.support');
Route::get('/pricing', [PageController::class, 'pricing'])->name('page.pricing');
Route::get('/my-report', [PageController::class, 'myReport'])->name('page.my-report');

//Iki auth Google
Route::middleware('guest')->group(function () {
    Route::get('/auth-page', function(){ return Inertia::render('auth/auth-page'); })->name('authPage');
    Route::prefix('auth/google')->name('google.')->controller(GoogleAuthController::class)->group(function () {
        Route::get('/redirect', 'redirect')->name('redirect');
        Route::get('/callback', 'callback')->name('callback');
    });
});


Route::post('/vehicle-check', [VehicleCheckController::class, 'store'])->name('vehicle-check.store');
Route::get('/vehicle-check/{vinCheck}/loading', [VehicleCheckController::class, 'loading'])->name('vehicle-check.loading');


require __DIR__.'/user.php';
require __DIR__.'/admin.php';