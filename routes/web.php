<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use App\Http\Controllers\VehicleCheckController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\MicrosoftAuthController;
use App\Http\Controllers\Webhook\StripeWebhookController;
use App\Http\Controllers\Webhook\PayPalWebhookController;
use App\Http\Controllers\ReportPdfController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Payments\GuestReportCheckoutController;
use Inertia\Inertia;



Route::get('/', [PageController::class, 'home'])
    ->middleware('redirect.home.auth')    
    ->name('page.home');

Route::get('/support', [PageController::class, 'support'])->name('page.support');
Route::get('/pricing', [PageController::class, 'pricing'])->name('page.pricing');
Route::get('/about-us', function(){return Inertia::render('about-us'); })->name('page.about-us');

//iki Auth Report
Route::get('/report/{report}', [ReportController::class, 'show'])->name('page.my-report.show');
Route::post('/guest-report/{report}/checkout', [GuestReportCheckoutController::class, 'start'])->name('guest-report.checkout');
Route::get('/guest-report-purchase/{purchase}/success', [GuestReportCheckoutController::class, 'paymentSuccess'])->name('guest-report.payment-success');
Route::get('/guest-report-purchase/{purchase}', [GuestReportCheckoutController::class, 'show'])->name('guest-report.show');
Route::get('/guest-report-purchase/{purchase}/view', [GuestReportCheckoutController::class, 'view'])->name('guest-report.view');
Route::get('/report/{report}/pdf', [ReportPdfController::class, 'download'])->name('report.pdf.download');
Route::post('/reports/{report}/unlock', [VehicleCheckController::class, 'unlock'])->name('report.unlock');

//Iki auth Google   
Route::middleware('guest')->group(function () {
    Route::get('/auth-page', function(){ return Inertia::render('auth/auth-page'); })->name('authPage');
    Route::prefix('auth/google')->name('google.')->controller(GoogleAuthController::class)->group(function () {
        Route::get('/redirect', 'redirect')->name('redirect');
        Route::get('/callback', 'callback')->name('callback');
    });
    Route::get('/auth-page', function(){ return Inertia::render('auth/auth-page'); })->name('authPage');
    Route::prefix('auth/microsoft')->name('microsoft.')->controller(MicrosoftAuthController::class)->group(function (){
        Route::get('/redirect', 'redirect')->name('redirect');
        Route::get('/callback', 'callback')->name('callback');
    });
});

//iki auth Loading Check
Route::post('/vehicle-check', [VehicleCheckController::class, 'store'])->name('vehicle-check.store');
Route::get('/vehicle-check/{vinCheck}/loading', [VehicleCheckController::class, 'loading'])->name('vehicle-check.loading');

//iki auth webhook Stripe dan PayPal
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->name('stripe.webhook');
Route::post('/paypal/webhook', [PayPalWebhookController::class, 'handle'])->name('paypal.webhook');


require __DIR__.'/user.php';
require __DIR__.'/admin.php';