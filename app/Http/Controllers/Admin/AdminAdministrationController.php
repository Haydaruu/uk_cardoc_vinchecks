<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelAdminInvitationRequest;
use App\Http\Requests\Admin\InviteAdminRequest;
use App\Http\Requests\Admin\ReactivateAdminRequest;
use App\Http\Requests\Admin\SuspendAdminRequest;
use App\Http\Requests\Admin\UpdateAdminRoleRequest;
use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Services\Admin\AdminAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class AdminAdministrationController extends Controller
{
    public function __construct(
        protected AdminAdministrationService $administrationService,
    ) {}

    /**
     * Render the administrator management page with admin roster and audit log trail.
     */
    public function index(Request $request): Response
    {
        $data = $this->administrationService->getAdministrators($request);
        $auditLogs = $this->administrationService->getAuditLogs($request);

        return Inertia::render('admin/administration', [
            'admins' => $data['admins'],
            'pending_invitations' => $data['pending_invitations'],
            'audit_logs' => $auditLogs,
            'filters' => [
                'action' => (string) $request->input('action', ''),
                'admin_id' => (string) $request->input('admin_id', ''),
                'outcome' => (string) $request->input('outcome', ''),
                'search' => (string) $request->input('search', ''),
            ],
        ]);
    }

    /**
     * Invite a new administrator or issue access invite.
     */
    public function invite(InviteAdminRequest $request): RedirectResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);

        $result = $this->administrationService->inviteAdmin(
            actor: $actor,
            email: (string) $request->validated('email'),
            name: (string) $request->validated('name'),
            role: (string) $request->validated('role'),
            reason: (string) $request->validated('reason'),
            request: $request,
            confirmElevation: (bool) $request->boolean('confirm_elevation'),
        );

        $invitation = $result['invitation'];
        $plaintextToken = $result['plaintext_token'];
        $acceptUrl = url('/admin/invitations/accept/'.$plaintextToken);

        try {
            Mail::to($invitation->email)->send(new AdminInvitationMail($invitation, $plaintextToken));
        } catch (\Throwable $e) {
            Log::warning('Failed to send admin invitation email: '.$e->getMessage());
        }

        return back()
            ->with('success', "Invitation issued to {$invitation->email} ({$invitation->role}).")
            ->with('invitation_url', $acceptUrl);
    }

    /**
     * Cancel an unaccepted administrator invitation.
     */
    public function cancelInvitation(CancelAdminInvitationRequest $request, AdminInvitation $invitation): RedirectResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);

        $email = $invitation->email;
        $this->administrationService->cancelInvitation(
            actor: $actor,
            invitation: $invitation,
            reason: (string) $request->validated('reason'),
            request: $request,
        );

        return back()->with('success', "Invitation for {$email} has been revoked.");
    }

    /**
     * Change an administrator's assigned role with Last Active Super Admin protection.
     */
    public function updateRole(UpdateAdminRoleRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);

        $newRole = (string) $request->validated('role');
        $this->administrationService->updateRole(
            actor: $actor,
            target: $user,
            newRole: $newRole,
            reason: (string) $request->validated('reason'),
            request: $request,
        );

        return back()->with('success', "Role for {$user->name} has been changed to {$newRole}.");
    }

    /**
     * Deactivate an administrator account with Last Active Super Admin protection.
     */
    public function suspend(SuspendAdminRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);

        $this->administrationService->suspendAdmin(
            actor: $actor,
            target: $user,
            reason: (string) $request->validated('reason'),
            request: $request,
        );

        return back()->with('success', "Administrator {$user->name} has been deactivated.");
    }

    /**
     * Reactivate a deactivated administrator account.
     */
    public function reactivate(ReactivateAdminRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        assert($actor instanceof User);

        $this->administrationService->reactivateAdmin(
            actor: $actor,
            target: $user,
            reason: (string) $request->validated('reason'),
            request: $request,
        );

        return back()->with('success', "Administrator {$user->name} has been reactivated.");
    }
}
