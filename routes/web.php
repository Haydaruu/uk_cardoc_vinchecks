<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use Illuminate\Http\Request;
use Laravel\Fortify\Features;
use App\Http\Controllers\Auth\GoogleAuthController;
use Inertia\Inertia;



Route::get('/', [PageController::class, 'home'])->name('home');


Route::inertia('/pricing', 'pricing')->name('pricing');
Route::inertia('/support', 'support')->name('support');
Route::inertia('/my-report', 'my-report')->name('my-report');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});


require __DIR__.'/settings.php';
require __DIR__.'/auth.php';