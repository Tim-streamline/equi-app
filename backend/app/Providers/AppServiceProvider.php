<?php

namespace App\Providers;

use App\Models\CommunityPost;
use App\Models\CommunityReply;
use App\Support\FreshViteManifest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Contracts\CreditPaymentGateway::class, \App\Support\UnconfiguredCreditPayments::class);
        $this->app->singleton(Vite::class, FreshViteManifest::class);
    }

    public function boot(): void
    {
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
        ]);
    }
}
