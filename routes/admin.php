<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

Route::middleware(['auth', 'verified', 'role:admin,superadmin'])
    ->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', fn () => Inertia::render('admin/dashboard'))->name('dashboard');
});

Route::middleware(['auth', 'verified', 'role:superadmin'])
    ->prefix('admin')->name('admin.')->group(function () {
        // nanti: manage-admin, ganti role, hapus akun
});