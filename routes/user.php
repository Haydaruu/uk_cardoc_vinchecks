<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\ConnectedAccountController;
use App\Http\Controllers\Settings\SubscriptionController;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function (){
    Route::get('/dashboard',[DashboardController::class, 'index'])
        ->name('user.dashboard');

    Route::get('/my-report', [PageController::class, 'myReport'])
        ->name('page.my-report');
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

//Route Settings
Route::middleware(['auth', 'verified'])->prefix('settings')->name('settings.')->group(function () {
    //Page Profile
    Route::get('/profile', [SettingsController::class, 'profile'])->name('profile');
    Route::patch('/profile', [SettingsController::class, 'updateProfile'])->name('profile.update');

    //Page Security
    Route::get('/security', [SettingsController::class, 'security'])->name('security');
    Route::put('/security/password', [SecurityController::class, 'updatePassword'])->name('security.password.update');
    Route::delete('/security/sessions/{sessionKey}', [securityController::class, 'destroySession'])->name('security.sessions.destroy');
    Route::delete('/security/account', [SecurityController::class, 'destroyAccount'])->name('security.account.destroy');

    //Page Connected Accounts
    Route::get('/connected-accounts', [SettingsController::class, 'connectedAccounts'])->name('connected-accounts');
    Route::get('/connected-accounts/{provider}/redirect', [ConnectedAccountController::class, 'redirect'])->name('connected-accounts.redirect');
    Route::get('/connected-accounts/{provider}/callback', [ConnectedAccountController::class, 'callback'])->name('connected-accounts.callback');
    Route::delete('/connected-accounts/{provider}', [ConnectedAccountController::class, 'destroy'])->name('connected-accounts.destroy');
    //Page Purchase History
    Route::get('/purchase-history', [SettingsController::class, 'purchaseHistory'])->name('purchase-history');

    //page Subscription
    Route::get('/subscription', [SettingsController::class, 'subscription'])->name('subscription');
    Route::post('/subscription/checkout', [SubscriptionController::class, 'checkout'])->name('subscription.checkout');
    Route::delete('/subscription', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');
    
    Route::get('/help', [SettingsController::class, 'help'])->name('help');
});
