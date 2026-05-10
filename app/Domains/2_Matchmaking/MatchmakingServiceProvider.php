<?php

namespace App\Domains\Matchmaking;

use Illuminate\Support\ServiceProvider;

class MatchmakingServiceProvider extends ServiceProvider
{
    

    public function register(): void
    {
        $this->app->bind(
            \App\Domains\Matchmaking\Contracts\MatchmakingRepositoryInterface::class,
            \App\Domains\Matchmaking\Infrastructure\QdrantMatchmakingRepository::class,
        );

        $this->app->bind(
            \App\Domains\Matchmaking\Contracts\HubMatchmakingRepositoryInterface::class,
            \App\Domains\Matchmaking\Infrastructure\QdrantHubMatchmakingRepository::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
