<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function profile(Request $request) : Response
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

    public function updateProfile(updateProfile $request)
    {
        $request->user()->update($request->validated());

        return back()->with('success', 'Profile updated succesfully.');
    }

    public function security(): Response
    {
        return Inertia::render('user/settings/security');
    }

    public function connectedAccounts(): Response
    {
        return Inertia::render('user/settings/connected-accounts');
    }

    public function purchaseHistory(): Response
    {
        return Inertia::render('user/settings/purchase-history');
    }

    public function susbscription(): Response
    {
        return Inertia::render('user/settings/subscription');
    }
    public function help(): Response
    {
        return Inertia::render('user/settings/help');
    }
}
