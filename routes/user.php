<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PageController;

use App\Http\Controllers\Payments\CheckoutController;
use App\Http\Controllers\Payments\StripeController;
use App\Http\Controllers\Payments\PayPalController;
use App\Http\Controllers\Payments\StripeSubscriptionController;
use App\Http\Controllers\Payments\PayPalSubscriptionController;

use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\ConnectedAccountController;

/*
|--------------------------------------------------------------------------
| Authenticated User Pages
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('user.dashboard');

    Route::get('/my-report', [PageController::class, 'myReport'])
        ->name('page.my-report');
});

/*
|--------------------------------------------------------------------------
| Checkout - Stripe
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    Route::get('/checkout', [CheckoutController::class, 'show'])
        ->name('checkout.show');

    Route::post('/checkout/create-intent', [StripeController::class, 'createOneTimeIntent'])
        ->name('checkout.create-intent');

    Route::post('/checkout/create-subscription-intent', [StripeSubscriptionController::class, 'createIntent'])
        ->name('checkout.create-subscription-intent');

    Route::get('/checkout/success', [StripeController::class, 'success'])
        ->name('checkout.success');

    Route::get('/checkout/subscription/success', [StripeSubscriptionController::class, 'success'])
        ->name('checkout.subscription.success');
});

/*
|--------------------------------------------------------------------------
| Checkout - PayPal
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {
    // One-time
    Route::post('/checkout/paypal/create-order', [PayPalController::class, 'createOrder'])
        ->name('checkout.paypal.create-order');

    Route::post('/checkout/paypal/capture', [PayPalController::class, 'capture'])
        ->name('checkout.paypal.capture');

    Route::get('/checkout/paypal/success', [PayPalController::class, 'success'])
        ->name('checkout.paypal.success');

    // Subscription
    Route::post('/checkout/paypal/subscription/create', [PayPalSubscriptionController::class, 'create'])
        ->name('checkout.paypal.subscription.create');

    Route::post('/checkout/paypal/subscription/confirm', [PayPalSubscriptionController::class, 'confirm'])
        ->name('checkout.paypal.subscription.confirm');

    Route::get('/checkout/paypal/subscription/success', [PayPalSubscriptionController::class, 'success'])
        ->name('checkout.paypal.subscription.success');
});

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])
    ->prefix('settings')
    ->name('settings.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Profile
        |--------------------------------------------------------------------------
        */

        Route::get('/profile', [SettingsController::class, 'profile'])
            ->name('profile');

        Route::patch('/profile', [SettingsController::class, 'updateProfile'])
            ->name('profile.update');

        /*
        |--------------------------------------------------------------------------
        | Security
        |--------------------------------------------------------------------------
        */

        Route::get('/security', [SettingsController::class, 'security'])
            ->name('security');

        Route::put('/security/password', [SecurityController::class, 'updatePassword'])
            ->name('security.password.update');

        Route::delete('/security/sessions/{sessionKey}', [SecurityController::class, 'destroySession'])
            ->name('security.sessions.destroy');

        Route::delete('/security/account', [SecurityController::class, 'destroyAccount'])
            ->name('security.account.destroy');

        /*
        |--------------------------------------------------------------------------
        | Connected Accounts
        |--------------------------------------------------------------------------
        */

        Route::get('/connected-accounts', [SettingsController::class, 'connectedAccounts'])
            ->name('connected-accounts');

        Route::get('/connected-accounts/{provider}/redirect', [ConnectedAccountController::class, 'redirect'])
            ->name('connected-accounts.redirect');

        Route::get('/connected-accounts/{provider}/callback', [ConnectedAccountController::class, 'callback'])
            ->name('connected-accounts.callback');

        Route::delete('/connected-accounts/{provider}', [ConnectedAccountController::class, 'destroy'])
            ->name('connected-accounts.destroy');

        /*
        |--------------------------------------------------------------------------
        | Purchase History
        |--------------------------------------------------------------------------
        */

        Route::get('/purchase-history', [SettingsController::class, 'purchaseHistory'])
            ->name('purchase-history');

        /*
        |--------------------------------------------------------------------------
        | Subscription
        |--------------------------------------------------------------------------
        */

        Route::get('/subscription', [SettingsController::class, 'subscription'])
            ->name('subscription');

        /*
        | Stripe
        */


        Route::delete('/subscription', [StripeSubscriptionController::class, 'cancel'])
            ->name('subscription.cancel');

        Route::patch('/subscription/plan', [StripeSubscriptionController::class, 'changePlan'])
            ->name('subscription.plan.change');

        /*
        | PayPal
        */

        Route::delete('/subscription/paypal', [PayPalSubscriptionController::class, 'cancel'])
            ->name('subscription.paypal.cancel');

        Route::patch('/subscription/paypal/plan', [PayPalSubscriptionController::class, 'changePlan'])
            ->name('subscription.paypal.plan.change');

        Route::get('/subscription/paypal/plan/approved', [PayPalSubscriptionController::class, 'changePlanApproved'])
            ->name('subscription.paypal.plan.approved');

        /*
        |--------------------------------------------------------------------------
        | Help
        |--------------------------------------------------------------------------
        */

        Route::get('/help', [SettingsController::class, 'help'])
            ->name('help');
    });