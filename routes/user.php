<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Models\User;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function (){
    Route::get('/dashboard',[DashboardController::class, 'index'])
        ->name('user.dashboard');
});

//Route Stripe

Route::middleware('auth')->group(function (){
    Route::get('/checkout', [PaymentController::class, 'show'])
        ->name('checkout.show');

    Route::post('/checkout/create-intent', [PaymentController::class, 'createOneTimeIntent'])
        ->name('checkout.create-intent');

    Route::get('/checkout/success', [PaymentController::class, 'success'])
        ->name('checkout.success');
});
