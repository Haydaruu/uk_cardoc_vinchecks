<?php

namespace App\Services\Admin;

use App\Models\AdminActivityLog;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Services\AdminAuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAdministrationService
{
    public function __construct(
        protected AdminAuditService $auditService,
    ) {}

    /**
     * Retrieve all administrators (role admin or super_admin).
     *
     * @return array{admins: array<int, array<string, mixed>>, pending_invitations: array<int, array<string, mixed>>}
     */
    public function getAdministrators(Request $request): array
    {
        /** @var Collection<int, User> $adminRecords */
        $adminRecords = User::whereIn('role', ['admin', 'super_admin'])
            ->with('suspendedBy:id,name')
            ->orderByRaw("CASE WHEN role = 'super_admin' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get();

        $admins = [];
        foreach ($adminRecords as $user) {
            $admins[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_suspended' => (bool) $user->is_suspended,
                'suspended_at' => $user->suspended_at instanceof Carbon ? $user->suspended_at->toIso8601String() : null,
                'suspended_reason' => $user->suspended_reason,
                'suspended_by' => $user->suspendedBy?->name,
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ];
        }

        /** @var Collection<int, AdminInvitation> $invitationRecords */
        $invitationRecords = AdminInvitation::with('inviter:id,name')
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get();

        $pendingInvitations = [];
        foreach ($invitationRecords as $inv) {
            $pendingInvitations[] = [
                'id' => $inv->id,
                'email' => $inv->email,
                'name' => $inv->name,
                'role' => $inv->role,
                'invited_by_name' => $inv->inviter->name,
                'expires_at' => $inv->expires_at->toIso8601String(),
                'created_at' => $inv->created_at?->toIso8601String(),
            ];
        }

        return [
            'admins' => $admins,
            'pending_invitations' => $pendingInvitations,
        ];
    }

    /**
     * Query global audit activity logs for Super Admin view with filtering and pagination.
     *
     * @return LengthAwarePaginator<int, AdminActivityLog>
     */
    public function getAuditLogs(Request $request): LengthAwarePaginator
    {
        $action = trim((string) $request->input('action', ''));
        $adminId = $request->input('admin_id');
        $outcome = $request->input('outcome');
        $search = trim((string) $request->input('search', ''));

        $query = AdminActivityLog::with('admin:id,name,email,role')
            ->latest('id');

        if ($action !== '') {
            $query->where('action', 'like', "%{$action}%");
        }

        if (! empty($adminId) && $adminId !== 'all') {
            $query->where('admin_id', (int) $adminId);
        }

        if (! empty($outcome) && $outcome !== 'all') {
            $query->where('outcome', $outcome);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('target_type', 'like', "%{$search}%")
                    ->orWhereHas('admin', function ($aq) use ($search) {
                        $aq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query->paginate(20)->withQueryString();
    }

    /**
     * Invite a new administrator or upgrade an existing user account to administrator.
     *
     * @return array{invitation: AdminInvitation, plaintext_token: string}
     */
    public function inviteAdmin(
        User $actor,
        string $email,
        string $name,
        string $role,
        string $reason,
        Request $request,
        bool $confirmElevation = false,
    ): array {
        $normalizedEmail = strtolower(trim($email));

        if (! in_array($role, ['admin', 'super_admin'], true)) {
            throw ValidationException::withMessages([
                'role' => ['The specified role must be either admin or super_admin.'],
            ]);
        }

        // Check if an account already exists with this email
        $existingUser = User::where('email', $normalizedEmail)->first();

        if ($existingUser !== null && in_array($existingUser->role, ['admin', 'super_admin'], true)) {
            throw ValidationException::withMessages([
                'email' => ['A user with this email address is already an active administrator.'],
            ]);
        }

        // Spec 7.3: When inviting an existing customer account, require explicit confirmation of privilege escalation
        if ($existingUser !== null && ! $confirmElevation) {
            throw ValidationException::withMessages([
                'confirm_elevation' => ['This email belongs to an existing customer account. Elevating it to an administrator requires explicit confirmation.'],
            ]);
        }

        // Check if an unexpired invitation already exists for this email
        $existingInvitation = AdminInvitation::where('email', $normalizedEmail)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existingInvitation !== null) {
            throw ValidationException::withMessages([
                'email' => ['A pending invitation has already been issued to this email address.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $normalizedEmail, $name, $role, $reason, $existingUser, $request) {
            $plaintextToken = Str::random(64);
            $hashedToken = hash('sha256', $plaintextToken);

            $invitation = AdminInvitation::create([
                'email' => $normalizedEmail,
                'name' => trim($name),
                'role' => $role,
                'token' => $hashedToken,
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(7),
            ]);

            $this->auditService->log(
                admin: $actor,
                action: 'admin.invited',
                targetType: 'admin_invitation',
                targetId: $invitation->id,
                reason: $reason,
                outcome: 'success',
                metadata: [
                    'invited_email' => $normalizedEmail,
                    'invited_name' => $name,
                    'assigned_role' => $role,
                    'is_existing_customer' => $existingUser !== null,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ],
                request: $request,
            );

            return [
                'invitation' => $invitation,
                'plaintext_token' => $plaintextToken,
            ];
        });
    }

    /**
     * Cancel / revoke a pending administrator invitation.
     */
    public function cancelInvitation(User $actor, AdminInvitation $invitation, string $reason, Request $request): void
    {
        DB::transaction(function () use ($actor, $invitation, $reason, $request) {
            $invitedEmail = $invitation->email;
            $role = $invitation->role;

            $invitation->delete();

            $this->auditService->log(
                admin: $actor,
                action: 'admin.invitation_cancelled',
                targetType: 'admin_invitation',
                targetId: $invitation->id,
                reason: $reason,
                outcome: 'success',
                metadata: [
                    'revoked_email' => $invitedEmail,
                    'revoked_role' => $role,
                ],
                request: $request,
            );
        });
    }

    /**
     * Change an administrator's role with Last Active Super Admin protection.
     */
    public function updateRole(User $actor, User $target, string $newRole, string $reason, Request $request): void
    {
        if (! in_array($newRole, ['admin', 'super_admin'], true)) {
            throw ValidationException::withMessages([
                'role' => ['The target role must be either admin or super_admin.'],
            ]);
        }

        if ($target->role === $newRole) {
            throw ValidationException::withMessages([
                'role' => ["This user is already assigned the '{$newRole}' role."],
            ]);
        }

        DB::transaction(function () use ($actor, $target, $newRole, $reason, $request) {
            // Guard: If demoting a super_admin to admin, enforce Last Active Super Admin protection
            if ($target->role === 'super_admin' && $newRole !== 'super_admin') {
                $activeSuperAdmins = User::where('role', 'super_admin')
                    ->where('is_suspended', false)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->get();

                if ($activeSuperAdmins->count() <= 1) {
                    throw ValidationException::withMessages([
                        'role' => ['Cannot demote the last remaining active Super Admin in the system.'],
                    ]);
                }
            }

            $oldRole = $target->role;
            $target->role = $newRole;
            $target->save();

            $this->auditService->log(
                admin: $actor,
                action: 'admin.role_changed',
                targetType: 'admin',
                targetId: $target->id,
                reason: $reason,
                outcome: 'success',
                metadata: [
                    'target_email' => $target->email,
                    'target_name' => $target->name,
                    'old_role' => $oldRole,
                    'new_role' => $newRole,
                ],
                request: $request,
            );
        });
    }

    /**
     * Deactivate / suspend an administrator with Last Active Super Admin protection.
     */
    public function suspendAdmin(User $actor, User $target, string $reason, Request $request): void
    {
        if ($target->is_suspended) {
            throw ValidationException::withMessages([
                'user' => ['This administrator account is already deactivated.'],
            ]);
        }

        DB::transaction(function () use ($actor, $target, $reason, $request) {
            // Guard: If deactivating a super_admin, enforce Last Active Super Admin protection
            if ($target->role === 'super_admin') {
                $activeSuperAdmins = User::where('role', 'super_admin')
                    ->where('is_suspended', false)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->get();

                if ($activeSuperAdmins->count() <= 1) {
                    throw ValidationException::withMessages([
                        'user' => ['Cannot deactivate the last remaining active Super Admin in the system.'],
                    ]);
                }
            }

            $target->update([
                'is_suspended' => true,
                'suspended_at' => now(),
                'suspended_reason' => $reason,
                'suspended_by' => $actor->id,
            ]);

            $this->auditService->log(
                admin: $actor,
                action: 'admin.deactivated',
                targetType: 'admin',
                targetId: $target->id,
                reason: $reason,
                outcome: 'success',
                metadata: [
                    'target_email' => $target->email,
                    'target_name' => $target->name,
                    'target_role' => $target->role,
                ],
                request: $request,
            );
        });
    }

    /**
     * Reactivate a deactivated administrator account.
     */
    public function reactivateAdmin(User $actor, User $target, string $reason, Request $request): void
    {
        if (! $target->is_suspended) {
            throw ValidationException::withMessages([
                'user' => ['This administrator account is not currently deactivated.'],
            ]);
        }

        DB::transaction(function () use ($actor, $target, $reason, $request) {
            $previousReason = $target->suspended_reason;

            $target->update([
                'is_suspended' => false,
                'suspended_at' => null,
                'suspended_reason' => null,
                'suspended_by' => null,
            ]);

            $this->auditService->log(
                admin: $actor,
                action: 'admin.reactivated',
                targetType: 'admin',
                targetId: $target->id,
                reason: $reason,
                outcome: 'success',
                metadata: [
                    'target_email' => $target->email,
                    'target_name' => $target->name,
                    'target_role' => $target->role,
                    'previous_suspended_reason' => $previousReason,
                ],
                request: $request,
            );
        });
    }
}
