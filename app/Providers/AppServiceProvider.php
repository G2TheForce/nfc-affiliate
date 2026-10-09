<?php

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditLogger::class, fn ($app) => new AuditLogger($app->bound('request') ? $app['request'] : null));
    }

    public function boot(): void
    {
        // Fail loudly in development on lazy loading and silently discarded attributes.
        Model::shouldBeStrict(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(12)->letters()->mixedCase()->numbers());

        $this->configureRateLimiting();
        $this->configureAuthLinks();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return [
                Limit::perMinute((int) config('affiliate.auth.login_attempts_per_minute'))->by($email.'|'.$request->ip()),
                Limit::perMinute(30)->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Emailed links point at the SPA, which calls the API.
     */
    private function configureAuthLinks(): void
    {
        VerifyEmail::createUrlUsing(fn (User $user) => URL::temporarySignedRoute(
            'api.v1.auth.verify-email',
            now()->addMinutes(60),
            ['user' => $user->public_id, 'hash' => sha1($user->getEmailForVerification())],
        ));

        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim((string) config('affiliate.frontend_url'), '/')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
    }
}
