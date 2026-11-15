<?php

namespace App\Providers;

use App\Contracts\CreditPaymentGateway;
use App\Models\CommunityPost;
use App\Models\CommunityReply;
use App\Models\User;
use App\Support\FreshViteManifest;
use App\Support\UnconfiguredCreditPayments;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CreditPaymentGateway::class, UnconfiguredCreditPayments::class);
        $this->app->singleton(Vite::class, FreshViteManifest::class);
    }

    public function boot(): void
    {
        // Failed attempts count per account and network, shared by web and native login, so other
        // people's typos behind a shared IP do not lock out older apps that refresh with the password.
        // A wider cap per IP still stops one network from trying many accounts. Successful logins
        // never count, so an account that signs in on many devices is not slowed down.
        RateLimiter::for('customer-login', function (Request $request) {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';
            $failed = fn ($response) => ! $response->isSuccessful();

            return [
                Limit::perMinute(10)->by('account:'.$email.'|'.$request->ip())->after($failed),
                Limit::perMinute(100)->by('ip:'.$request->ip())->after($failed),
            ];
        });
        // Admin sign-in redirects both on success and on failure, and an admin who is already signed
        // in can still post wrong passwords. Only this attempt's validation failure counts, never the
        // session's state, so the limit applies to guesses and never to correct sign-ins.
        RateLimiter::for('admin-login', function (Request $request) {
            $email = is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : '';
            $failed = fn ($response) => ($response->exception ?? null) instanceof ValidationException;

            return [
                Limit::perMinute(10)->by('account:'.$email.'|'.$request->ip())->after($failed),
                Limit::perMinute(100)->by('ip:'.$request->ip())->after($failed),
            ];
        });
        // Signed-in API calls count per account and per endpoint, never per network: people behind
        // one carrier or stable IP must not use up each other's budget. Each name is its own bucket.
        $perAccount = fn (Request $request) => (string) ($request->attributes->get('powersync_user_id') ?? 'ip:'.$request->ip());
        foreach ([
            'protocol-purchase-create' => 30,
            'intake-attachment' => 60,
            'web-push-subscribe' => 30,
            'library-unlock' => 60,
            'credit-purchase' => 15,
            'credit-temporary-top-up' => 15,
        ] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($perAccount($request)));
        }
        // Device tokens cannot be guessed, so exchanges get their own, larger budget: other people's
        // failed password logins behind the same IP must not stop this app's sync.
        RateLimiter::for('device-token', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->ip())
            ->after(fn ($response) => ! $response->isSuccessful()));
        RateLimiter::for('community-write', fn (Request $request) => Limit::perMinute(60)
            ->by($request->attributes->get('powersync_user_id') ?? $request->ip()));
        RateLimiter::for('shelby-message', fn (Request $request) => Limit::perMinute(6)
            ->by($request->attributes->get('powersync_user_id')));
        RateLimiter::for('feed-scan-create', fn (Request $request) => Limit::perHour(20)
            ->by($request->attributes->get('powersync_user_id')));
        RateLimiter::for('feed-scan-upload', fn (Request $request) => Limit::perMinute(60)
            ->by($request->attributes->get('powersync_user_id')));
        RateLimiter::for('feed-scan-analyse', fn (Request $request) => Limit::perHour(10)
            ->by($request->attributes->get('powersync_user_id')));
        RateLimiter::for('feed-scan-check', fn (Request $request) => Limit::perHour(30)
            ->by($request->attributes->get('powersync_user_id')));
        RateLimiter::for('feed-scan-confirm', fn (Request $request) => Limit::perHour(30)
            ->by($request->attributes->get('powersync_user_id')));

        // Map polymorphic target_type values stored in community_reactions
        // to model classes. Keeps stored values short ('post' / 'reply')
        // and decoupled from class namespaces.
        Relation::enforceMorphMap([
            'post' => CommunityPost::class,
            'reply' => CommunityReply::class,
            // Owner of the app's device tokens (Sanctum personal access tokens).
            'user' => User::class,
        ]);
    }
}
