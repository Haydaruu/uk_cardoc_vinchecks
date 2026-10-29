<?php

use App\Mail\AdminInvitationMail;
use App\Models\AdminActivityLog;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->regularUser = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
    ]);

    $this->admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'email_verified_at' => now(),
    ]);
});

test('guest, regular user, and operational admin cannot access administration endpoints', function () {
    // Guest
    $this->get(route('admin.administration.index'))->assertRedirect(route('login'));

    // Regular user
    $this->actingAs($this->regularUser)->get(route('admin.administration.index'))->assertForbidden();
    $this->actingAs($this->regularUser)->post(route('admin.administration.invitations.store'), [
        'email' => 'new@admin.test',
        'name' => 'New Admin',
        'role' => 'admin',
        'reason' => 'Unauthorized invite',
    ])->assertForbidden();

    // Operational admin (non-super)
    $this->actingAs($this->admin)->get(route('admin.administration.index'))->assertForbidden();
    $this->actingAs($this->admin)->post(route('admin.administration.invitations.store'), [
        'email' => 'new@admin.test',
        'name' => 'New Admin',
        'role' => 'admin',
        'reason' => 'Operational admin invite attempt',
    ])->assertForbidden();
});

test('super admin can view administration dashboard with admins roster and audit trail', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('admin.administration.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/administration')
            ->has('admins')
            ->has('pending_invitations')
            ->has('audit_logs')
        );
});

test('super admin can invite a new administrator with audit logging', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.invitations.store'), [
            'email' => 'ops.manager@vincheck.test',
            'name' => 'Operations Manager',
            'role' => 'admin',
            'reason' => 'Onboarding new operational support team lead',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $invitation = AdminInvitation::where('email', 'ops.manager@vincheck.test')->first();
    expect($invitation)->not->toBeNull();
    expect($invitation->role)->toBe('admin');
    expect(strlen($invitation->token))->toBe(64);
    expect($invitation->isPending())->toBeTrue();

    // Verify audit log
    $log = AdminActivityLog::where('action', 'admin.invited')
        ->where('admin_id', $this->superAdmin->id)
        ->first();
    expect($log)->not->toBeNull();
    expect($log->reason)->toBe('Onboarding new operational support team lead');
    expect($log->metadata['invited_email'])->toBe('ops.manager@vincheck.test');
});

test('cannot invite an email that already belongs to an existing administrator', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.invitations.store'), [
            'email' => $this->admin->email,
            'name' => 'Duplicate Admin',
            'role' => 'admin',
            'reason' => 'Attempting duplicate invite',
        ])
        ->assertSessionHasErrors(['email']);
});

test('inviting an existing customer account requires explicit confirmation of privilege escalation', function () {
    // 1. Without confirm_elevation flag -> rejected with confirm_elevation error
    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.invitations.store'), [
            'email' => $this->regularUser->email,
            'name' => $this->regularUser->name,
            'role' => 'admin',
            'reason' => 'Elevating customer to operational console analyst',
            'confirm_elevation' => false,
        ])
        ->assertSessionHasErrors(['confirm_elevation']);

    // 2. With confirm_elevation = true -> succeeds
    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.invitations.store'), [
            'email' => $this->regularUser->email,
            'name' => $this->regularUser->name,
            'role' => 'admin',
            'reason' => 'Elevating customer to operational console analyst with explicit confirmation',
            'confirm_elevation' => true,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $invitation = AdminInvitation::where('email', $this->regularUser->email)->first();
    expect($invitation)->not->toBeNull();
    expect($invitation->isPending())->toBeTrue();
});

test('end-to-end invitation acceptance flow allows new administrator to configure password and activate access', function () {
    Mail::fake();

    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.invitations.store'), [
            'email' => 'new.recruit@vincheck.test',
            'name' => 'New Recruit',
            'role' => 'admin',
            'reason' => 'Onboarding new recruit for customer service support',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertSent(AdminInvitationMail::class);

    $invitation = AdminInvitation::where('email', 'new.recruit@vincheck.test')->first();
    expect($invitation)->not->toBeNull();

    // Plaintext token is available via the Mailable or returned invitation_url
    $mail = Mail::sent(AdminInvitationMail::class)->first();
    $plaintextToken = $mail->plaintextToken;

    // Verify token is hashed in DB
    expect($invitation->token)->toBe(hash('sha256', $plaintextToken));

    // 1. Guest visits acceptance page
    $this->get("/admin/invitations/accept/{$plaintextToken}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/invitation-accept')
            ->where('invitation.email', 'new.recruit@vincheck.test')
            ->where('is_existing_user', false)
        );

    // 2. Guest submits account setup and password
    $this->post("/admin/invitations/accept/{$plaintextToken}", [
        'name' => 'New Recruit Official',
        'password' => 'SecureP@ssw0rd123!',
        'password_confirmation' => 'SecureP@ssw0rd123!',
    ])->assertRedirect(route('admin.dashboard'));

    // 3. User account is now created with role 'admin'
    $createdUser = User::where('email', 'new.recruit@vincheck.test')->first();
    expect($createdUser)->not->toBeNull();
    expect($createdUser->role)->toBe('admin');
    expect(Hash::check('SecureP@ssw0rd123!', $createdUser->password))->toBeTrue();

    // 4. Invitation is marked as accepted
    $freshInvitation = $invitation->fresh();
    expect($freshInvitation->accepted_at)->not->toBeNull();
    expect($freshInvitation->isPending())->toBeFalse();

    // 5. Attempting to reuse the same token is strictly rejected
    $this->get("/admin/invitations/accept/{$plaintextToken}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/invitation-invalid'));

    $this->post("/admin/invitations/accept/{$plaintextToken}", [
        'name' => 'Replay Attempt',
        'password' => 'AnotherP@ss123!',
        'password_confirmation' => 'AnotherP@ss123!',
    ])->assertSessionHasErrors(['token']);
});

test('existing customer accepting an invitation requires password confirmation to prevent account takeover', function () {
    Mail::fake();

    $customer = User::factory()->create([
        'email' => 'existing.client@vincheck.test',
        'role' => 'user',
        'password' => Hash::make('ClientSecret123!'),
    ]);

    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.invitations.store'), [
            'email' => $customer->email,
            'name' => $customer->name,
            'role' => 'admin',
            'reason' => 'Internal transfer from client account to support staff',
            'confirm_elevation' => true,
        ])
        ->assertRedirect();

    $mail = Mail::sent(AdminInvitationMail::class)->first();
    $plaintextToken = $mail->plaintextToken;

    // Acceptance with wrong password fails
    $this->post("/admin/invitations/accept/{$plaintextToken}", [
        'password' => 'WrongPassword!',
    ])->assertSessionHasErrors(['password']);

    expect($customer->fresh()->role)->toBe('user');

    // Acceptance with correct password succeeds and elevates role
    $this->post("/admin/invitations/accept/{$plaintextToken}", [
        'password' => 'ClientSecret123!',
    ])->assertRedirect(route('admin.dashboard'));

    expect($customer->fresh()->role)->toBe('admin');
});

test('super admin can cancel a pending invitation', function () {
    $invitation = AdminInvitation::create([
        'email' => 'cancel.me@vincheck.test',
        'name' => 'Candidate',
        'role' => 'admin',
        'token' => 'token_for_revocation_test_1234567890123456789012345678901234567890',
        'invited_by' => $this->superAdmin->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->superAdmin)
        ->delete(route('admin.administration.invitations.destroy', $invitation), [
            'reason' => 'Candidate withdrew application prior to onboarding',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(AdminInvitation::find($invitation->id))->toBeNull();

    $log = AdminActivityLog::where('action', 'admin.invitation_cancelled')->first();
    expect($log)->not->toBeNull();
    expect($log->reason)->toBe('Candidate withdrew application prior to onboarding');
});

test('super admin can promote an admin to super_admin', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.admins.role', $this->admin), [
            'role' => 'super_admin',
            'reason' => 'Promotion to Lead Systems Architect',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->admin->fresh()->role)->toBe('super_admin');

    $log = AdminActivityLog::where('action', 'admin.role_changed')->first();
    expect($log)->not->toBeNull();
    expect($log->metadata['old_role'])->toBe('admin');
    expect($log->metadata['new_role'])->toBe('super_admin');
});

test('backend strictly prevents demoting the last remaining active super admin', function () {
    // Only $this->superAdmin exists as super_admin
    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.admins.role', $this->superAdmin), [
            'role' => 'admin',
            'reason' => 'Attempting to self-demote sole super admin',
        ])
        ->assertSessionHasErrors(['role']);

    expect($this->superAdmin->fresh()->role)->toBe('super_admin');
});

test('backend strictly prevents deactivating the last remaining active super admin', function () {
    // Only $this->superAdmin exists as super_admin
    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.admins.suspend', $this->superAdmin), [
            'reason' => 'Attempting to self-deactivate sole super admin',
        ])
        ->assertSessionHasErrors(['user']);

    expect($this->superAdmin->fresh()->is_suspended)->toBeFalse();
});

test('super admin can demote another super admin when multiple active super admins exist', function () {
    $secondSuperAdmin = User::factory()->create([
        'role' => 'super_admin',
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.admins.role', $secondSuperAdmin), [
            'role' => 'admin',
            'reason' => 'Organizational restructuring of supervisory roles',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($secondSuperAdmin->fresh()->role)->toBe('admin');
});

test('super admin can deactivate and reactivate an administrator account', function () {
    // 1. Deactivate
    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.admins.suspend', $this->admin), [
            'reason' => 'Extended leave of absence requested by employee',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $freshAdmin = $this->admin->fresh();
    expect($freshAdmin->is_suspended)->toBeTrue();
    expect($freshAdmin->suspended_reason)->toBe('Extended leave of absence requested by employee');

    $deactivateLog = AdminActivityLog::where('action', 'admin.deactivated')->first();
    expect($deactivateLog)->not->toBeNull();

    // 2. Reactivate
    $this->actingAs($this->superAdmin)
        ->post(route('admin.administration.admins.reactivate', $this->admin), [
            'reason' => 'Return from leave of absence confirmed by HR',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $reactivatedAdmin = $this->admin->fresh();
    expect($reactivatedAdmin->is_suspended)->toBeFalse();
    expect($reactivatedAdmin->suspended_reason)->toBeNull();

    $reactivateLog = AdminActivityLog::where('action', 'admin.reactivated')->first();
    expect($reactivateLog)->not->toBeNull();
});

test('no permanent delete endpoint exists for administrator accounts', function () {
    $this->actingAs($this->superAdmin)
        ->delete("/admin/administration/admins/{$this->admin->id}")
        ->assertNotFound();
});
