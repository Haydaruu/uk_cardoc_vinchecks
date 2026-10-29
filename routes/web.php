<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\MicrosoftAuthController;
use App\Http\Controllers\GuestReportInvoiceController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\Payments\GuestReportCheckoutController;
use App\Http\Controllers\Payments\GuestReportCheckoutSuccessController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportPdfController;
use App\Http\Controllers\VehicleCheckController;
use App\Http\Controllers\Webhook\PayPalWebhookController;
use App\Http\Controllers\Webhook\StripeWebhookController;
use App\Http\Controllers\SupportMessageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [PageController::class, 'home'])
    ->middleware('redirect.home.auth')
    ->name('page.home');

Route::get('/support', [PageController::class, 'support'])->name('page.support');
Route::post('/support', [SupportMessageController::class, 'store'])->middleware('throttle:5,1')->name('page.support.submit');
Route::get('/pricing', [PageController::class, 'pricing'])->name('page.pricing');
Route::get('/terms-and-conditions', function () {return Inertia::render('terms-and-conditions');})->name('page.terms-and-conditions');
Route::get('/about-us', function () { return Inertia::render('about-us');})->name('page.about-us');

Route::get('/guest-purchases/{purchase}/invoice', [GuestReportInvoiceController::class, 'show'])->middleware('signed')->name('guest-report.invoice.show');
Route::get('/guest-purchases/{purchase}/invoice/pdf', [GuestReportInvoiceController::class, 'download'])->middleware('signed')->name('guest-report.invoice.download');
Route::get('/guest-checkout/success', GuestReportCheckoutSuccessController::class)->name('guest-report.checkout.success');
Route::get('/guest-purchases/{purchase}/checkout-status', [GuestReportCheckoutSuccessController::class, 'status'])->middleware('signed')->name('guest-report.checkout.status');

// iki Auth Report
Route::get('/report/{report}', [ReportController::class, 'show'])->name('page.my-report.show');
Route::get('/report/{report}/pdf', [ReportPdfController::class, 'download'])->name('report.pdf.download');
Route::post('/reports/{report}/unlock', [VehicleCheckController::class, 'unlock'])->middleware('not.suspended')->name('report.unlock');
Route::post('/reports/{report}/guest-checkout', [GuestReportCheckoutController::class, 'store'])->name('guest-report.checkout');
Route::get('/reports/{report}/guest-checkout', [GuestReportCheckoutController::class, 'show'])->name('guest-report.checkout.show');
Route::post('/reports/{report}/guest-checkout/prepare', [GuestReportCheckoutController::class, 'prepare'])->name('guest-report.checkout.prepare');

// Iki auth Google
Route::middleware('guest')->group(function () {
    Route::get('/auth-page', function () {
        return Inertia::render('auth/auth-page');
    })->name('authPage');
    Route::prefix('auth/google')->name('google.')->controller(GoogleAuthController::class)->group(function () {
        Route::get('/redirect', 'redirect')->name('redirect');
        Route::get('/callback', 'callback')->name('callback');
    });
    Route::get('/auth-page', function () {
        return Inertia::render('auth/auth-page');
    })->name('authPage');
    Route::prefix('auth/microsoft')->name('microsoft.')->controller(MicrosoftAuthController::class)->group(function () {
        Route::get('/redirect', 'redirect')->name('redirect');
        Route::get('/callback', 'callback')->name('callback');
    });
});

// iki auth Loading Check
Route::post('/vehicle-check', [VehicleCheckController::class, 'store'])->middleware('not.suspended')->name('vehicle-check.store');
Route::get('/vehicle-check/{vinCheck}/loading', [VehicleCheckController::class, 'loading'])->name('vehicle-check.loading');

// iki auth webhook Stripe dan PayPal
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->name('stripe.webhook');
Route::post('/paypal/webhook', [PayPalWebhookController::class, 'handle'])->name('paypal.webhook');

require __DIR__.'/user.php';
require __DIR__.'/admin.php';
