<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Socialite\Socialite;
use App\Models\SocialAccount;

class ConnectedAccountController extends Controller
{
    private function ensureSupportedProvider(string $provider): void
    {
        abort_unless(in_array($provider, ['google', 'microsoft'], true),
        404
        );
    }

    public function redirect(string $provider)
    {
        $this->ensureSupportedProvider($provider);

        config([
            "services.{$provider}.redirect" =>
                route(
                    'settings.connected-accounts.callback',
                    ['provider' => $provider]
                ),
        ]);

        return Socialite::driver($provider)->redirect();

    }

    public function callback(Request $request, string $provider)
    {
         $this->ensureSupportedProvider($provider);

        config([
            "services.{$provider}.redirect" =>
                route(
                    'settings.connected-accounts.callback',
                    ['provider' => $provider]
                ),
        ]);

        $user = $request->user();

        $providerUser = Socialite::driver($provider)->user();

        $existingAccount = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $providerUser
            ->getId())
            ->first();

        if ( $existingAccount && $existingAccount->user_id !== $user->id)
            {
                return redirect()
                    ->route('settings.connected-accounts')
                    ->with('error', ucfirst($provider). ' account is already connected to another UKCardoc account.');
            }
        
        $currentProvider = $user->socialAccounts()
            ->where('provider', $provider)
            ->first();

        if( $currentProvider && $currentProvider->provider_user_id !== $providerUser->getId())
            {
                return redirect()
                    ->route('settings.connected-accounts')
                    ->with('error', ucfirst($provider). 'is already connected. Disconnect it before connecting another account.');
            }
    
        
        SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $provider,
            ],
            [
                'provider_user_id' => $providerUser->getId(),
                'provider_email' => $providerUser->getEmail(),
                'avatar' => $providerUser->getAvatar(),
            ]
        );

        return redirect()->route('settings.connected-accounts')->with('success', ucfirst($provider).' account connected successfully');

    }

    public function destroy(Request $request, string $provider)
    {
        $this->ensureSupportedProvider($provider);

        $user = $request->user();

        $account = $user->socialAccounts()
            ->where('provider', $provider)
            ->firstOrFail();

        $loginMethods = $user->socialAccounts()->count();

        if(! is_null($user->password)) {
            $loginMethods ++;
        }

        if ($loginMethods <= 1){
            return back()->with(
                'error', ucfirst($provider). ' cannot be disconected because it is your only sign-in method. '
            );
        }

        $account->delete();

        return back()->with('success', ucfirst($provider). ' account disconnected is successfully.');
    }
}
