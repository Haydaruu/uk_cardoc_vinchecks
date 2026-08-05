<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;
use Inertia\Inertia;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Inertia::encryptHistory();

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });

        Fortify::loginView(function () {
            return Inertia::render('auth/auth-page', ['initialTab' => 'login']);
        });

        Fortify::registerView(function () {
            return Inertia::render('auth/auth-page', ['initialTab' => 'register']);
        });

        Fortify::requestPasswordResetLinkView(function () {
            return Inertia::render('auth/forgot-password');
        });

        Fortify::resetPasswordView(function (Request $request) {
            return Inertia::render('auth/forgot-password', [
                'token' => $request->route('token'),
                'email' => $request->query('email'),
            ]);
        });

        Fortify::verifyEmailView(function () {
            return Inertia::render('auth/verify-email');
        });

        $this->app->singleton(RegisterResponse::class, function () {
            return new class implements RegisterResponse {
                public function toResponse($request)
                {
                    Inertia::clearHistory();

                    $user = $request->user();

                    if(!$user->hasVerifiedEmail()) {
                        return redirect()->route('verification.notice');
                    }

                    return redirect()->route(
                        in_array($user->role, ['admin', 'super_admin']) ? 'admin.dashboard' : 'user.dashboard'
                    );
                }
            };
        });

        $this->app->singleton(LoginResponse::class, function () {
            return new class implements LoginResponse {
                public function toResponse($request)
                {
                    Inertia::clearHistory();

                    $user = $request->user();

                    if(!$user->hasVerifiedEmail()) {
                        return redirect()->route('verification.notice');
                    }

                    return redirect()->route(
                        in_array($user->role, ['admin', 'super_admin']) ? 'admin.dashboard' : 'user.dashboard'
                    );
                }
            };
        });

        $this->app->singleton(LogoutResponse::class, function () {
            return new class implements LogoutResponse {
                public function toResponse($request)
                {
                    Inertia::clearHistory();

                    return redirect()->route('page.home');
                }
            };
        });
    }
}
