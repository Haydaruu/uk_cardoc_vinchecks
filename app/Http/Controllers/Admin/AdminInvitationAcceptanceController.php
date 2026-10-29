<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminInvitationAcceptanceController extends Controller
{
    public function __construct(
        protected AdminAuditService $auditService,
    ) {}

    /**
     * Show the acceptance page for an invitation token.
     */
    public function show(string $token): Response
    {
        $hashedToken = hash('sha256', $token);
        $invitation = AdminInvitation::where('token', $hashedToken)->first();

        if ($invitation === null || $invitation->accepted_at !== null || $invitation->isExpired()) {
            return Inertia::render('admin/invitation-invalid', [
                'reason' => $invitation === null
                    ? 'The invitation token was not found or is invalid.'
                    : ($invitation->accepted_at !== null
                        ? 'This invitation has already been accepted.'
                        : 'This invitation has expired. Invitations are valid for 7 days.'),
            ]);
        }

        $existingUser = User::where('email', $invitation->email)->first();
        /** @var User|null $currentUser */
        $currentUser = Auth::user();

        $isLoggedInAsTarget = $currentUser !== null
            && strtolower($currentUser->email) === strtolower($invitation->email);

        return Inertia::render('admin/invitation-accept', [
            'invitation' => [
                'email' => $invitation->email,
                'name' => $invitation->name,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            'token' => $token,
            'is_existing_user' => $existingUser !== null,
            'is_logged_in_as_target' => $isLoggedInAsTarget,
            'logged_in_email' => $currentUser?->email,
        ]);
    }

    /**
     * Process acceptance, password configuration, and role elevation.
     */
    public function accept(string $token, Request $request): RedirectResponse
    {
        $hashedToken = hash('sha256', $token);
        $invitation = AdminInvitation::where('token', $hashedToken)->first();

        if ($invitation === null || $invitation->accepted_at !== null || $invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => ['This invitation is invalid, expired, or has already been accepted.'],
            ]);
        }

        $existingUser = User::where('email', $invitation->email)->first();
        /** @var User|null $currentUser */
        $currentUser = Auth::user();

        $isLoggedInAsTarget = $currentUser !== null
            && strtolower($currentUser->email) === strtolower($invitation->email);

        // Validation rules depending on whether the user is new or existing
        if ($existingUser !== null) {
            if (! $isLoggedInAsTarget) {
                $request->validate([
                    'password' => ['required', 'string'],
                ], [
                    'password.required' => 'Please enter your existing account password to confirm ownership before claiming administrator access.',
                ]);

                if (! Hash::check((string) $request->password, $existingUser->password)) {
                    throw ValidationException::withMessages([
                        'password' => ['The password you entered does not match your existing account password.'],
                    ]);
                }
            }
        } else {
            $request->validate([
                'name' => ['required', 'string', 'min:2', 'max:100'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
        }

        /** @var User $finalUser */
        $finalUser = DB::transaction(function () use ($invitation, $existingUser, $request) {
            /** @var AdminInvitation|null $lockedInvitation */
            $lockedInvitation = AdminInvitation::where('id', $invitation->id)
                ->lockForUpdate()
                ->first();

            if ($lockedInvitation === null || $lockedInvitation->accepted_at !== null || $lockedInvitation->isExpired()) {
                throw ValidationException::withMessages([
                    'token' => ['This invitation is no longer valid.'],
                ]);
            }

            $assignedRole = in_array($lockedInvitation->role, ['admin', 'super_admin'], true)
                ? $lockedInvitation->role
                : 'admin';

            if ($existingUser !== null) {
                $existingUser->role = $assignedRole;
                if ($existingUser->email_verified_at === null) {
                    $existingUser->email_verified_at = Carbon::now();
                }
                $existingUser->save();
                $user = $existingUser;
            } else {
                $user = User::create([
                    'name' => (string) $request->input('name', $lockedInvitation->name),
                    'email' => $lockedInvitation->email,
                    'role' => $assignedRole,
                    'password' => Hash::make((string) $request->password),
                    'email_verified_at' => Carbon::now(),
                ]);
            }

            $lockedInvitation->accepted_at = Carbon::now();
            $lockedInvitation->save();

            $this->auditService->log(
                admin: $user,
                action: 'admin.invitation_accepted',
                targetType: 'user',
                targetId: $user->id,
                reason: "Invitation accepted for role {$lockedInvitation->role}",
                outcome: 'success',
                metadata: [
                    'email' => $user->email,
                    'assigned_role' => $lockedInvitation->role,
                    'is_existing_user' => $existingUser !== null,
                ],
                request: $request,
            );

            return $user;
        });

        Auth::login($finalUser);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with(
            'success',
            "Welcome to the console, {$finalUser->name}. Your {$finalUser->role} access has been activated."
        );
    }
}
