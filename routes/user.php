<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Models\User;
use Inertia\Inertia;

Route::middleware(['auth', 'verified', 'no_cache'])->group(function (){
    Route::get('/dashboard', fn() => Inertia::render('user/dashboard'))
        ->name('user.dashboard');
});