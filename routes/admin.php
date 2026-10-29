<?php

use App\Http\Controllers\Admin\AdminAdministrationController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\Admin\AdminCustomerController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminInvitationAcceptanceController;
use App\Http\Controllers\Admin\AdminRefundController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\Admin\AdminSystemConfigurationController;
use App\Http\Controllers\Admin\AdminSystemHealthController;
use App\Http\Controllers\Admin\AdminTransactionController;
use App\Http\Controllers\SuperAdmin\ProviderComparisonController;
use Illuminate\Support\Facades\Route;

// 6 Shared Operational Pages accessible by 'admin' and 'super_admin'
Route::middleware(['auth', 'verified', 'role:admin,super_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/transactions', [AdminTransactionController::class, 'index'])->name('transactions.index');
        Route::get('/reports', [AdminReportController::class, 'index'])->name('reports.index');
        Route::get('/customers', [AdminCustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/{user}', [AdminCustomerController::class, 'show'])->name('customers.show');
        Route::post('/customers/{user}/credits', [AdminCustomerController::class, 'adjustCredits'])->name('customers.credits.adjust');
        Route::post('/customers/{user}/suspend', [AdminCustomerController::class, 'suspend'])->name('customers.suspend');
        Route::post('/customers/{user}/reactivate', [AdminCustomerController::class, 'reactivate'])->name('customers.reactivate');
        Route::get('/subscriptions', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::post('/subscriptions/{subscription}/cancel', [AdminSubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
        Route::post('/transactions/{transaction}/refund', [AdminRefundController::class, 'refundTransaction'])->name('transactions.refund');
        Route::post('/guest-reports/{guestReportPurchase}/refund', [AdminRefundController::class, 'refundGuestReport'])->name('guest-reports.refund');
        Route::get('/analytics', [AdminAnalyticsController::class, 'index'])->name('analytics.index');
    });

// Dedicated Super Admin Pages (Role-gated on backend)
Route::middleware(['auth', 'verified', 'role:super_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // Phase 5: System Health & Diagnostics (Super Admin only)
        Route::get('/system-health', [AdminSystemHealthController::class, 'index'])->name('system-health.index');
        Route::post('/system-health/refresh', [AdminSystemHealthController::class, 'refresh'])->name('system-health.refresh');

        Route::get('/provider-lab', [ProviderComparisonController::class, 'index'])->name('provider-lab.index');
        Route::post('/provider-lab/run', [ProviderComparisonController::class, 'run'])->name('provider-lab.run');

        // Phase 4: System Configuration (Super Admin only)
        Route::get('/system-configuration', [AdminSystemConfigurationController::class, 'index'])->name('system-configuration.index');
        Route::post('/system-configuration', [AdminSystemConfigurationController::class, 'update'])->name('system-configuration.update');

        // Phase 4: Admin Administration (Super Admin only)
        Route::get('/administration', [AdminAdministrationController::class, 'index'])->name('administration.index');
        Route::post('/administration/invitations', [AdminAdministrationController::class, 'invite'])->name('administration.invitations.store');
        Route::delete('/administration/invitations/{invitation}', [AdminAdministrationController::class, 'cancelInvitation'])->name('administration.invitations.destroy');
        Route::post('/administration/admins/{user}/role', [AdminAdministrationController::class, 'updateRole'])->name('administration.admins.role');
        Route::post('/administration/admins/{user}/suspend', [AdminAdministrationController::class, 'suspend'])->name('administration.admins.suspend');
        Route::post('/administration/admins/{user}/reactivate', [AdminAdministrationController::class, 'reactivate'])->name('administration.admins.reactivate');
    });

// Phase 4: Public Admin Invitation Acceptance (Token verification & Password Setup)
Route::prefix('admin/invitations')->name('admin.invitations.')->group(function () {
    Route::get('/accept/{token}', [AdminInvitationAcceptanceController::class, 'show'])->name('accept.show');
    Route::post('/accept/{token}', [AdminInvitationAcceptanceController::class, 'accept'])->name('accept.store');
});
