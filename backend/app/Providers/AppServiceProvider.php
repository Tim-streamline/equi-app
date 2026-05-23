<?php

namespace App\Providers;

use App\Models\CommunityPost;
use App\Models\CommunityReply;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Map polymorphic target_type values stored in community_reactions
        // to model classes. Keeps stored values short ('post' / 'reply')
        // and decoupled from class namespaces.
        Relation::enforceMorphMap([
            'post' => CommunityPost::class,
            'reply' => CommunityReply::class,
        ]);
    }
}
