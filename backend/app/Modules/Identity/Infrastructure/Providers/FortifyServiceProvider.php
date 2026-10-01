<?php

namespace App\Modules\Identity\Infrastructure\Providers;

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Identity\Infrastructure\Fortify\CreateNewUser;
use App\Modules\Identity\Infrastructure\Fortify\ResetUserPassword;
use App\Modules\Identity\Presentation\Http\Responses\LoginResponse;
use App\Modules\Identity\Presentation\Http\Responses\PasswordResetLinkResponse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        // Signed verification links must use the public API origin, not the
        // internal host name introduced by the development proxy.
        URL::forceRootUrl((string) config('app.url'));

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('email:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password/'.$token.'?email='.rawurlencode((string) $user->getEmailForPasswordReset());
        });
    }
}
