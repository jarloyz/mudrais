<?php

namespace App\Domains\Narrative;

use App\Domains\Matchmaking\Events\PlayersMatchedEvent;
use App\Domains\Narrative\Contracts\LoreRepositoryInterface;
use App\Domains\Narrative\Contracts\SceneRepositoryInterface;
use App\Domains\Narrative\Infrastructure\EloquentSceneRepository;
use App\Domains\Narrative\Infrastructure\QdrantLoreRepository;
use App\Domains\Narrative\Listeners\CreateSceneFromMatchListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NarrativeServiceProvider extends ServiceProvider
{
    

    public function register(): void
    {
        $this->app->bind(
            SceneRepositoryInterface::class,
            EloquentSceneRepository::class,
        );

        $this->app->bind(
            LoreRepositoryInterface::class,
            QdrantLoreRepository::class,
        );
    }

    public function boot(): void
    {
        // El dominio Narrative escucha los matches del dominio Matchmaking
        Event::listen(
            PlayersMatchedEvent::class,
            CreateSceneFromMatchListener::class,
        );
    }
}
