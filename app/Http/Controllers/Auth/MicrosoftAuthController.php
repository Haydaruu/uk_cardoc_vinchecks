<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Socialite;
use App\Models\User;
use App\Models\SocialAccount;
use Inertia\Inertia;
use Illuminate\Support\Str;

class MicrosoftAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('microsoft')->redirect();
    }

    public function callback()
    {
        $microsoftUser = Socialite::driver('microsoft')->user();

        $socialAccount = SocialAccount::where('provider', 'microsoft')
            ->where('provider_user_id', $microsoftUser->getId())
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;
        } else {
            $user = User::where(
                'email',
                $microsoftUser->getEmail()
            )->first();

            if (!$user) {
                $user = User::create([
                    'name' => $microsoftUser->getName(),
                    'email' => $microsoftUser->getEmail(),
                    'email_verified_at' => now(),
                    'password' => null,
                    'avatar' => $microsoftUser->getAvatar(),
                    'last_login_at' => now(),
                ]);
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => 'microsoft',
                'provider_user_id' => $microsoftUser->getId(),
                'provider_email' => $microsoftUser->getEmail(),
                'avatar' => $microsoftUser->getAvatar(),
            ]);
        }

        $user->update([
            'last_login_at' => now(),
        ]);

        Auth::login($user);
        Inertia::clearHistory();

        return match ($user->role) {
            'admin', 'super_admin' => redirect()->route('admin.dashboard'),
            default => redirect()->route('user.dashboard'),
        };
    }
}
