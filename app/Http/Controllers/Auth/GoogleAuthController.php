<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Socialite;
use App\Models\User;
use Inertia\Inertia;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
     public function redirect() {
        return Socialite::driver('google')->redirect();
    }

    public function callback(){
        $googleUser = Socialite::driver('google')->user();

            $user = User::updateOrCreate(
                ['google_id' => $googleUser->id],
                [
                    'name' =>$googleUser->name,
                    'email' =>$googleUser->email,
                    'phone_number'=> null,
                    'provider' => 'google',
                    'password' => Str::password(12),
                    'email_verified_at' => now(),
                ]
            );

        Auth::login($user);

        return Inertia::render('home');
    }
}
