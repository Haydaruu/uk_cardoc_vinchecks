<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;

Route::get('/', [PageController::class, 'welcome'])->name('page.welcome');
Route::get('/home', [PageController::class, 'home'])->name('page.home');
Route::get('/support', [PageController::class, 'support'])->name('page.support');
Route::get('/pricing', [PageController::class, 'pricing'])->name('page.pricing');
Route::get('/my-report', [PageController::class, 'myReport'])->name('page.my-report');


require __DIR__.'/auth.php';