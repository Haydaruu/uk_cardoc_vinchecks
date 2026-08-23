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

class GoogleAuthController extends Controller
{
    public function redirect() 
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->user();

        $socialAccount = SocialAccount::where('provider', 'google')
            ->where('provider_user_id', $googleUser->getId())
            ->first();

        if($socialAccount) {
            $user = $socialAccount->user;    
        }else {
             $user = User::where('email', $googleUser->getEmail())->first();

        if(!$user){
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                'email_verified_at' => now(),
                'password' => null,
                'avatar' => $googleUser->getAvatar(),
                'last_login_at' => now(),
            ]);
        }

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => $googleUser->getId(),
            'provider_email' => $googleUser->getEmail(),
            'avatar' => $googleUser->getAvatar(),
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
