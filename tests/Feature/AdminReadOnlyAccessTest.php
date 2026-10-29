<?php

use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;

test('unauthenticated guests are redirected to login when accessing admin routes', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('admin.transactions.index'))->assertRedirect(route('login'));
    $this->get(route('admin.reports.index'))->assertRedirect(route('login'));
    $this->get(route('admin.customers.index'))->assertRedirect(route('login'));
    $this->get(route('admin.subscriptions.index'))->assertRedirect(route('login'));
    $this->get(route('admin.analytics.index'))->assertRedirect(route('login'));
    $this->get(route('admin.provider-lab.index'))->assertRedirect(route('login'));
});

test('regular users cannot access any admin routes and receive 403 forbidden', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.transactions.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.reports.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.customers.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.subscriptions.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.analytics.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.provider-lab.index'))->assertForbidden();
});

test('unverified admin cannot access operational dashboard routes', function () {
    $unverifiedAdmin = User::factory()->unverified()->create([
        'role' => 'admin',
    ]);

    $this->actingAs($unverifiedAdmin)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('admin can access all six shared operational pages', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $customer = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
    ]);

    // 1. Overview
    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/dashboard'));

    // 2. Transactions
    $this->actingAs($admin)
        ->get(route('admin.transactions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/transactions'));

    // 3. Reports
    $this->actingAs($admin)
        ->get(route('admin.reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/reports'));

    // 4. Customers List
    $this->actingAs($admin)
        ->get(route('admin.customers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/customers'));

    // 4b. Customer Detail
    $this->actingAs($admin)
        ->get(route('admin.customers.show', $customer->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/customer-detail'));

    // 5. Subscriptions
    $this->actingAs($admin)
        ->get(route('admin.subscriptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/subscriptions'));

    // 6. Analytics
    $this->actingAs($admin)
        ->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/analytics'));
});

test('admin is strictly forbidden from accessing super admin only routes', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    // Provider Lab Index
    $this->actingAs($admin)
        ->get(route('admin.provider-lab.index'))
        ->assertForbidden();

    // Provider Lab Run
    $this->actingAs($admin)
        ->post(route('admin.provider-lab.run'), ['registration_number' => 'ABC1234'])
        ->assertForbidden();
});

test('super admin can access both shared operational pages and provider lab', function () {
    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($superAdmin)
        ->get(route('admin.dashboard'))
        ->assertOk();

    $this->actingAs($superAdmin)
        ->get(route('admin.provider-lab.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('super-admin/provider-lab'));
});

test('financial summary accurately separates gross, refunds, and net collected per currency', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $user = User::factory()->create();

    // 1. GBP Successful Payment
    Transaction::create([
        'user_id' => $user->id,
        'invoice_id' => 'INV-GBP-01',
        'currency' => 'GBP',
        'amount' => 100.00,
        'type' => 'payment',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    // 2. GBP Refunded Payment
    Transaction::create([
        'user_id' => $user->id,
        'invoice_id' => 'INV-GBP-02',
        'currency' => 'GBP',
        'amount' => 40.00,
        'type' => 'refund',
        'status' => 'refunded',
        'paid_at' => now()->subDay(),
        'refunded_at' => now(),
    ]);

    // 3. Guest GBP Purchase: 2000 minor units = £20.00
    $vehicle = Vehicle::create(['registration_number' => 'GUEST-FIN']);
    $report = Report::create(['vehicle_id' => $vehicle->id, 'report_type' => 'basic']);
    GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Guest Fin',
        'email' => 'fin@example.com',
        'phone' => '07000000000',
        'payment_status' => 'paid',
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'paid_at' => now(),
    ]);

    // 4. EUR Payment: 50.00 EUR
    Transaction::create([
        'user_id' => $user->id,
        'invoice_id' => 'INV-EUR-01',
        'currency' => 'EUR',
        'amount' => 50.00,
        'type' => 'payment',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get(route('admin.transactions.index'));
    $response->assertOk();

    $response->assertInertia(function ($page) {
        $financial = $page->toArray()['props']['financialSummary'];

        // GBP: Gross = 100 + 40 (paid) + 20 (guest) = 160.00; Refunds = 40.00; Net = 120.00
        expect((float) $financial['GBP']['gross'])->toEqual(160.0)
            ->and((float) $financial['GBP']['refunds'])->toEqual(40.0)
            ->and((float) $financial['GBP']['net_collected'])->toEqual(120.0);

        // EUR: Gross = 50.00, Refunds = 0.00, Net = 50.00
        expect((float) $financial['EUR']['gross'])->toEqual(50.0)
            ->and((float) $financial['EUR']['refunds'])->toEqual(0.0)
            ->and((float) $financial['EUR']['net_collected'])->toEqual(50.0);
    });
});
