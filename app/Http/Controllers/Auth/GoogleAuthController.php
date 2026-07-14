<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Socialite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
                    'password' => Str::password(12),
                    'email_verified_at' => now(),
                ]
            );

        Auth::login($user);

        return Inertia::render('home');
    }
}
