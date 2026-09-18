<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SuperAdmin\ProviderComparisonController;
use Inertia\Inertia;

Route::middleware(['auth', 'verified', 'role:admin,super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('admin/dashboard'))->name('dashboard');
});

Route::middleware(['auth', 'verified', 'role:super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/provider-lab',[ProviderComparisonController::class, 'index'])->name('provider-lab.index');
    Route::post('/provider-lab/run',[ProviderComparisonController::class, 'run'])->name('provider-lab.run');
});