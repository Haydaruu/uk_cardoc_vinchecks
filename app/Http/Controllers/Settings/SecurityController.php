<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DeleteAccountRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class SecurityController extends Controller
{
    public function updatePassword( Request $request, UpdatesUserPasswords $passwordUpdater)
    {
        $user = $request->user();

        //social acc only
        if(is_null($user->password)) {
            $validated = $request->validate([
                'password' => [
                    'required', 
                    'string', 
                    Password::default(), 
                    'confirmed',
                ],
            ]);

            $user->forceFill([
                'password' => Hash::make($validated['password']),
            ])->save();

            return back()->with(
                'success',
                'Password has been set succesfully.'
            );
        }

        $passwordUpdater->update(
            $user,
            $request->all()
        );

        return back()->with(
            'success',
            'password updated succsefully.',
        );
    }

    public function destroySession(Request $request, string $sessionKey)
    {
        $user = $request->user();

        $session = DB::table('sessions')
            ->where('user_id', $user->id)
            ->get();

        $matchedSession = $session->first(function ($session) use ($sessionKey){
            $expectedKey = hash_hmac(
                'sha256',
                $session->id,
                config('app.key'),
            );

            return hash_equals($expectedKey, $sessionKey);
        });

        if(! $matchedSession) {
            abort(404);
        }

        if($matchedSession->id === $request->session()->getId()) {
            return back()->with(
                'error',
                'You Cannot sign out your current session from here.'
            );
        }

        DB::table('sessions')
            ->where('id', $matchedSession->id)
            ->where('user_id', $user->id)
            ->delete();
        
        return back()->with(
            'success',
            'Session signed out successfully.'
        );
    }

    public function destroyAccount(DeleteAccountRequest $request)
    {
        $user = $request->user();
        $originalEmail = $user->email;

        if ($user->is_premium) {
            return back()->withErrors([
                'account' =>
                    'Please cancel your active susbscription before deleting your account.',
            ]);
        }
        DB::transaction(function () use (
            $user,
            $originalEmail,
        ) {
            DB::table('password_reset_tokens')
                ->where('email', $originalEmail)
                ->delete();
            
                $user->socialAccounts()->delete();

            $user->forceFill([
                'name' => 'Deleted Account',
                'email' => null,
                'phone_number' => null,
                'password' => null,
                'credits' => 0,
                'remember_token' => null,
            ])->save();

            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();

        });

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}

