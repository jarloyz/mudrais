<?php

namespace App\Domains\Community;

use App\Domains\Community\Contracts\GuildRepositoryInterface;
use App\Domains\Community\Contracts\PlayerRepositoryInterface;
use App\Domains\Community\Repositories\EloquentPlayerRepository;
use Illuminate\Support\ServiceProvider;

class CommunityServiceProvider extends ServiceProvider
{
    

    public function register(): void
    {
        $this->app->bind(
            PlayerRepositoryInterface::class,
            EloquentPlayerRepository::class,
        );

        // GuildRepositoryInterface: implementación pendiente de crear
        // $this->app->bind(GuildRepositoryInterface::class, EloquentGuildRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
