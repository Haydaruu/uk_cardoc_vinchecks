<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function profile(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('user/settings/profile', [
            'profile' => [
                'name' => $user->name,
                'phone_number' => $user->phone_number,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'avatar' => $user->avatar,
                'credits' => $user->credits,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $request->user()->update(
            $request->validated()
        );

        return back()->with(
            'success',
            'Profile updated successfully.'
        );
    }

    public function security(Request $request): Response
    {  
        $user = $request->user();

        $currentSessionId = $request->session()->getId();

        $session = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($session) use ($currentSessionId){ 

            $agent = $session->user_agent ?? '';

            $device = 'Unknown Device';
            $browser = 'Unknown Browser';

            if(str_contains($agent, 'Chrome')) {
                $browser = 'Chrome';
            }

            if(str_contains($agent, 'Windows')) {
                $device = 'Windows';
            }

            return[
                'key' => hash_hmac('sha256', $session->id, config('app.key')),
                'browser' => $browser,
                'device' => $device,
                'last_activity' => $session->last_activity,
                'is_current' => $session->id === $currentSessionId,
            ];

        });

        return Inertia::render('user/settings/security',[
            'security' => [
                'has_password' => ! is_null($request->user()->password),
                'sessions' => $session,
            ],
        ]);
    }

    public function connectedAccounts(): Response
    {
        return Inertia::render('user/settings/connected-accounts');
    }

    public function purchaseHistory(): Response
    {
        return Inertia::render('user/settings/purchase-history');
    }

    public function subscription(): Response
    {
        return Inertia::render('user/settings/subscription');
    }

    public function help(): Response
    {
        return Inertia::render('user/settings/help');
    }
}